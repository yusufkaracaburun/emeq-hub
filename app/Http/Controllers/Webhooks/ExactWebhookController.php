<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Jobs\Webhooks\ForwardExactWebhookToConsumerJob;
use App\Models\Connection;
use App\Webhooks\InboundWebhookRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exact Online webhook-ingress.
 *
 * Aangeroepen ná `verify.exact.signature` (SDK-side middleware) — de HashCode is
 * hier al gevalideerd. Parse de Content-node, check idempotency, resolve de
 * Connection op de division (`Content.Division`), audit via de provider-agnostische
 * InboundWebhookRecorder en dispatch de async fan-out.
 *
 * Exact-specifiek:
 *  - Bij subscribe POST't Exact direct een **lege-body-validatieping** die de
 *    middleware doorlaat → hier 200, geen audit/fan-out (anders faalt de subscription).
 *  - Exact draagt geen natuurlijke notification-id; de idempotency-sleutel is een
 *    hash van de raw body (identiek op een Exact-retry → dedup).
 *  - Onbekende division → 200 + unknown_tenant-audit (anti-retry-storm).
 */
final class ExactWebhookController extends Controller
{
    public function __construct(private readonly InboundWebhookRecorder $recorder) {}

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
            $this->recorder->record(Provider::Exact->value, $request, 400, InboundWebhookRecorder::OUTCOME_MALFORMED);

            return response()->json(['error' => 'malformed_payload'], 400);
        }

        $topic = isset($content['Topic']) && is_string($content['Topic']) ? $content['Topic'] : null;
        $action = isset($content['Action']) && is_string($content['Action']) ? $content['Action'] : null;
        $eventId = $this->deriveEventId($rawBody);

        if ($this->recorder->isDuplicate(Provider::Exact->value, $eventId)) {
            $this->recorder->record(Provider::Exact->value, $request, 200, InboundWebhookRecorder::OUTCOME_DUPLICATE, $eventId, $topic, $action);

            return response('', 200);
        }

        // Eén administratie kan door meerdere Accounts gekoppeld zijn — de boekhouder
        // via de ene Consumer-app, de ondernemer via de andere. Het schema staat dat
        // toe: de unique zit op (account, provider), de index op (provider,
        // administratie_id) is niet uniek. `->first()` leverde er dan één willekeurige,
        // dus kreeg één partij de webhook en de ander niets — en over Consumer-grenzen
        // heen is dat een levering aan de verkeerde partij.
        /** @var list<Connection> $connections */
        $connections = Connection::query()
            ->where('provider', Provider::Exact->value)
            ->where('administratie_id', $division)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get()
            ->all();

        if ($connections === []) {
            $this->recorder->record(Provider::Exact->value, $request, 200, InboundWebhookRecorder::OUTCOME_UNKNOWN_TENANT, $eventId, $topic, $action);

            return response('', 200);
        }

        // `inbound_webhook_events` is uniek op (provider, event_id) — dat is de
        // dedupe-sleutel voor retries — dus er is één auditrij per inkomende webhook,
        // niet per ontvanger. Die rij wijst de laagste connectie aan; de volledige
        // ontvangerslijst gaat naar de log zodat de fan-out traceerbaar blijft.
        $this->recorder->record(
            Provider::Exact->value,
            $request,
            200,
            InboundWebhookRecorder::OUTCOME_PROCESSED,
            $eventId,
            $topic,
            $action,
            $connections[0],
            InboundWebhookRecorder::FANOUT_DISPATCHED,
        );

        if (count($connections) > 1) {
            Log::info('webhook.fanout_multiple_connections', [
                'provider' => Provider::Exact->value,
                'event_id' => $eventId,
                'division' => $division,
                'connection_ids' => array_map(static fn (Connection $c): int => $c->id, $connections),
            ]);
        }

        foreach ($connections as $connection) {
            ForwardExactWebhookToConsumerJob::dispatch($connection, $payload, $eventId);
        }

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
}
