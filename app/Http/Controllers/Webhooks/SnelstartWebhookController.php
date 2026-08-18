<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Integrations\Webhooks\InboundWebhookRecorder;
use App\Jobs\Webhooks\ForwardWebhookToConsumerJob;
use App\Models\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class SnelstartWebhookController extends Controller
{
    public function __construct(private readonly InboundWebhookRecorder $recorder) {}

    public function __invoke(Request $request): Response
    {
        $payload = $request->json()->all();

        if (! is_array($payload) || ! isset($payload['administratieId']) || ! is_string($payload['administratieId'])) {
            $this->recorder->record(Provider::Snelstart->value, $request, 400, InboundWebhookRecorder::OUTCOME_MALFORMED);

            return response()->json(['error' => 'malformed_payload'], 400);
        }

        $eventIdKey = (string) config('snelstart.webhook.event_id_key', 'eventId');
        $eventId = isset($payload[$eventIdKey]) && is_string($payload[$eventIdKey])
            ? $payload[$eventIdKey]
            : null;
        $topic = isset($payload['type']) && is_string($payload['type']) ? $payload['type'] : null;

        if ($eventId !== null && $this->recorder->isDuplicate(Provider::Snelstart->value, $eventId)) {
            $this->recorder->record(Provider::Snelstart->value, $request, 200, InboundWebhookRecorder::OUTCOME_DUPLICATE, $eventId, $topic);

            return response('', 200);
        }

        /** @var list<Connection> $connections */
        $connections = Connection::query()
            ->where('provider', Provider::Snelstart->value)
            ->where('administratie_id', $payload['administratieId'])
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get()
            ->all();

        if ($connections === []) {
            $this->recorder->record(Provider::Snelstart->value, $request, 200, InboundWebhookRecorder::OUTCOME_UNKNOWN_TENANT, $eventId, $topic);

            return response('', 200);
        }

        $this->recorder->record(
            Provider::Snelstart->value,
            $request,
            200,
            InboundWebhookRecorder::OUTCOME_PROCESSED,
            $eventId,
            $topic,
            null,
            $connections[0],
            InboundWebhookRecorder::FANOUT_DISPATCHED,
        );

        if (count($connections) > 1) {
            Log::info('webhook.fanout_multiple_connections', [
                'provider' => Provider::Snelstart->value,
                'event_id' => $eventId,
                'connection_ids' => array_map(static fn (Connection $c): int => $c->id, $connections),
            ]);
        }

        foreach ($connections as $connection) {
            ForwardWebhookToConsumerJob::dispatch(Provider::Snelstart, $connection, $payload, $eventId ?? 'no-id');
        }

        return response('', 200);
    }
}
