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

final class ExactWebhookController extends Controller
{
    public function __construct(private readonly InboundWebhookRecorder $recorder) {}

    public function __invoke(Request $request): Response
    {
        $rawBody = $request->getContent();

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
            ForwardWebhookToConsumerJob::dispatch(Provider::Exact, $connection, $payload, $eventId);
        }

        return response('', 200);
    }

    private function deriveEventId(string $rawBody): string
    {
        return hash('sha256', $rawBody);
    }
}
