<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Jobs\Webhooks\ForwardExactWebhookToConsumerJob;
use App\Models\Connection;
use App\Models\PassThroughCall;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exact Online webhook-ingress.
 *
 * Aangeroepen ná `verify.exact.signature` (SDK-side middleware) — de HashCode is
 * hier al gevalideerd. Spiegelt SnelstartWebhookController: parse payload, check
 * idempotency, resolve de Connection op de division (`Content.Division`), schrijf
 * audit en dispatch de async fan-out.
 *
 * Exact-specifiek:
 *  - Bij subscribe POST't Exact direct een **lege-body-validatieping** die de
 *    middleware doorlaat → hier 200, geen audit/fan-out (anders faalt de subscription).
 *  - Exact draagt geen natuurlijke notification-id; de idempotency-sleutel is een
 *    hash van de raw body (identiek op een Exact-retry → dedup).
 *  - Onbekende division → 200 + NULL-tenant audit (anti-retry-storm; Exact hertried
 *    non-2xx tot 10× over ~34u).
 */
final class ExactWebhookController extends Controller
{
    private const PATH = '/webhooks/exact';

    public function __invoke(Request $request): Response
    {
        $rawBody = $request->getContent();

        // Validatie-ping: lege body → 200/201, geen audit of fan-out.
        if (mb_trim($rawBody) === '') {
            return response('', 200);
        }

        $payload = $request->json()->all();
        $content = is_array($payload) && isset($payload['Content']) && is_array($payload['Content'])
            ? $payload['Content']
            : null;

        $division = $content !== null && isset($content['Division']) && (is_string($content['Division']) || is_int($content['Division']))
            ? (string) $content['Division']
            : null;

        if ($division === null || $division === '') {
            $this->auditMalformed($request, $rawBody);

            return response()->json(['error' => 'malformed_payload'], 400);
        }

        $eventId = $this->deriveEventId($rawBody);

        if ($this->isDuplicateEvent($eventId)) {
            PassThroughCall::create([
                'direction' => 'inbound',
                'provider' => Provider::Exact->value,
                'method' => $request->getMethod(),
                'path' => self::PATH,
                'status' => 200,
                'duration_ms' => 0,
                'request_fingerprint' => $this->fingerprint($rawBody),
                // event_id bewust NULL — anders triggert de (provider, event_id)
                // unique-index. upstream_error houdt forensics.
                'upstream_error' => 'duplicate_event',
            ]);

            return response('', 200);
        }

        $connection = Connection::query()
            ->where('provider', Provider::Exact->value)
            ->where('administratie_id', $division)
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null) {
            PassThroughCall::create([
                'direction' => 'inbound',
                'provider' => Provider::Exact->value,
                'method' => $request->getMethod(),
                'path' => self::PATH,
                'status' => 200,
                'duration_ms' => 0,
                'request_fingerprint' => $this->fingerprint($rawBody),
                'event_id' => $eventId,
                'upstream_error' => 'unknown_division',
            ]);

            return response('', 200);
        }

        PassThroughCall::create([
            'direction' => 'inbound',
            'consumer_id' => $connection->account->consumer_id,
            'account_id' => $connection->account_id,
            'connection_id' => $connection->id,
            'provider' => Provider::Exact->value,
            'method' => $request->getMethod(),
            'path' => self::PATH,
            'status' => 200,
            'duration_ms' => 0,
            'request_fingerprint' => $this->fingerprint($rawBody),
            'event_id' => $eventId,
        ]);

        ForwardExactWebhookToConsumerJob::dispatch($connection, $payload, $eventId);

        return response('', 200);
    }

    /**
     * Exact draagt geen notification-id; de raw body is stabiel over een retry
     * (identieke bytes), dus z'n hash is een veilige idempotency-sleutel.
     */
    private function deriveEventId(string $rawBody): string
    {
        return hash('sha256', $rawBody);
    }

    private function isDuplicateEvent(string $eventId): bool
    {
        return PassThroughCall::query()
            ->inbound()
            ->where('provider', Provider::Exact->value)
            ->where('event_id', $eventId)
            ->exists();
    }

    private function auditMalformed(Request $request, string $rawBody): void
    {
        PassThroughCall::create([
            'direction' => 'inbound',
            'provider' => Provider::Exact->value,
            'method' => $request->getMethod(),
            'path' => self::PATH,
            'status' => 400,
            'duration_ms' => 0,
            'request_fingerprint' => $this->fingerprint($rawBody),
            'upstream_error' => 'malformed_payload',
        ]);
    }

    private function fingerprint(string $rawBody): ?string
    {
        return $rawBody === '' ? null : mb_substr(hash('sha256', $rawBody), 0, 12);
    }
}
