<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\Webhooks\ForwardSnelstartWebhookToConsumerJob;
use App\Models\Connection;
use App\Models\PassThroughCall;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Snelstart webhook-ingress (HUB-06).
 *
 * Aangeroepen ná `verify.snelstart.signature` (SDK-side middleware) — de
 * signature is hier al gevalideerd. Deze controller parsed de payload,
 * checkt idempotency, resolved de Connection op `administratieId`, schrijft
 * audit en dispatcht de async fan-out-job.
 *
 * Decisions uit 05c-CONTEXT.md:
 *  - Onbekende `administratieId` → 200 + NULL-tenant audit, geen fan-out
 *    (anti-retry-storm; Snelstart hertried 4xx niet maar 5xx wel)
 *  - Audit-reuse: `pass_through_calls` met `direction=inbound`
 *  - Fan-out async (Spatie webhook-server) zodat we <500ms ack'en
 */
final class SnelstartWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $rawBody = $request->getContent();
        $payload = $request->json()->all();

        if (! is_array($payload) || ! isset($payload['administratieId']) || ! is_string($payload['administratieId'])) {
            $this->auditMalformed($request, $rawBody);

            return response()->json(['error' => 'malformed_payload'], 400);
        }

        $eventIdKey = (string) config('snelstart.webhook.event_id_key', 'eventId');
        $eventId = isset($payload[$eventIdKey]) && is_string($payload[$eventIdKey])
            ? $payload[$eventIdKey]
            : null;

        if ($eventId !== null && $this->isDuplicateEvent($eventId)) {
            PassThroughCall::create([
                'direction' => 'inbound',
                'provider' => 'snelstart',
                'method' => $request->getMethod(),
                'path' => '/webhooks/snelstart',
                'status' => 200,
                'duration_ms' => 0,
                'request_fingerprint' => $this->fingerprint($rawBody),
                // event_id bewust NULL — anders triggert de (provider, event_id)
                // unique-index uit plan 05c-01. upstream_error houdt forensics.
                'upstream_error' => 'duplicate_event',
            ]);

            return response('', 200);
        }

        $connection = Connection::query()
            ->where('provider', 'snelstart')
            ->where('administratie_id', $payload['administratieId'])
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null) {
            PassThroughCall::create([
                'direction' => 'inbound',
                'provider' => 'snelstart',
                'method' => $request->getMethod(),
                'path' => '/webhooks/snelstart',
                'status' => 200,
                'duration_ms' => 0,
                'request_fingerprint' => $this->fingerprint($rawBody),
                'event_id' => $eventId,
                'upstream_error' => 'unknown_administratie_id',
            ]);

            return response('', 200);
        }

        PassThroughCall::create([
            'direction' => 'inbound',
            'consumer_id' => $connection->account->consumer_id,
            'account_id' => $connection->account_id,
            'connection_id' => $connection->id,
            'provider' => 'snelstart',
            'method' => $request->getMethod(),
            'path' => '/webhooks/snelstart',
            'status' => 200,
            'duration_ms' => 0,
            'request_fingerprint' => $this->fingerprint($rawBody),
            'event_id' => $eventId,
        ]);

        ForwardSnelstartWebhookToConsumerJob::dispatch(
            $connection,
            $payload,
            $eventId ?? 'no-id',
        );

        return response('', 200);
    }

    private function isDuplicateEvent(string $eventId): bool
    {
        return PassThroughCall::query()
            ->inbound()
            ->where('provider', 'snelstart')
            ->where('event_id', $eventId)
            ->exists();
    }

    private function auditMalformed(Request $request, string $rawBody): void
    {
        PassThroughCall::create([
            'direction' => 'inbound',
            'provider' => 'snelstart',
            'method' => $request->getMethod(),
            'path' => '/webhooks/snelstart',
            'status' => 400,
            'duration_ms' => 0,
            'request_fingerprint' => $this->fingerprint($rawBody),
            'upstream_error' => 'malformed_payload',
        ]);
    }

    private function fingerprint(string $rawBody): ?string
    {
        return $rawBody === '' ? null : substr(hash('sha256', $rawBody), 0, 12);
    }
}
