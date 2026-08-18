<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Integrations\Mollie\Webhooks\WebhookHandlerResult;
use App\Integrations\Mollie\Webhooks\WebhookPayloadRouter;
use App\Integrations\Webhooks\InboundWebhookRecorder;
use App\Jobs\Webhooks\ForwardWebhookToConsumerJob;
use App\Models\Connection;
use Emeq\MollieApi\Webhooks\MollieWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\InvalidSignatureException;

class MollieWebhookController extends Controller
{
    public function __construct(
        private readonly WebhookPayloadRouter $router,
        private readonly InboundWebhookRecorder $recorder,
    ) {}

    public function __invoke(Request $request, int $connection_id): JsonResponse
    {
        $secret = config('mollie.webhook.secret');
        if (! is_string($secret) || $secret === '') {
            $this->recorder->record(Provider::Mollie->value, $request, 500, InboundWebhookRecorder::OUTCOME_MISCONFIGURED);

            return response()->json(['error' => 'webhook_misconfigured'], 500);
        }

        try {
            $valid = MollieWebhookSignature::verify($request, $secret);
        } catch (InvalidSignatureException $e) {
            $this->recorder->record(Provider::Mollie->value, $request, 400, InboundWebhookRecorder::OUTCOME_INVALID_SIGNATURE);

            return response()->json(['error' => 'invalid_signature'], 400);
        }
        if (! $valid) {
            $this->recorder->record(Provider::Mollie->value, $request, 400, InboundWebhookRecorder::OUTCOME_INVALID_SIGNATURE);

            return response()->json(['error' => 'missing_signature'], 400);
        }

        $connection = Connection::query()
            ->where('id', $connection_id)
            ->where('provider', Provider::Mollie->value)
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null) {
            $this->recorder->record(Provider::Mollie->value, $request, 410, InboundWebhookRecorder::OUTCOME_UNKNOWN_TENANT);

            return response()->json(['error' => 'connection_gone'], 410);
        }

        $payload = $request->json()->all();
        if (! is_array($payload) || ! isset($payload['id']) || ! is_string($payload['id'])) {
            $this->recorder->record(Provider::Mollie->value, $request, 400, InboundWebhookRecorder::OUTCOME_MALFORMED, connection: $connection);

            return response()->json(['error' => 'missing_id'], 400);
        }

        $result = $this->router->routeFor($payload['id'], $payload, $connection);

        if ($result->shouldAudit()) {
            $this->auditResult($request, $connection, $result);
        }

        if ($result->status === 'anti_spoof_failed') {
            return response()->json(['error' => 'resource_ownership_failed'], 400);
        }

        if ($result->shouldFanOut()) {
            ForwardWebhookToConsumerJob::dispatch(Provider::Mollie, $connection, $payload, null, null);
        }

        return response()->json(['status' => 'accepted'], 202);
    }

    private function auditResult(Request $request, Connection $connection, WebhookHandlerResult $result): void
    {
        [$status, $outcome, $fanout] = match ($result->status) {
            'anti_spoof_failed' => [400, InboundWebhookRecorder::OUTCOME_INVALID_SIGNATURE, InboundWebhookRecorder::FANOUT_NOT_APPLICABLE],
            'skip' => [202, InboundWebhookRecorder::OUTCOME_PROCESSED, InboundWebhookRecorder::FANOUT_NOT_APPLICABLE],
            default => [202, InboundWebhookRecorder::OUTCOME_PROCESSED, InboundWebhookRecorder::FANOUT_DISPATCHED],
        };

        $this->recorder->record(
            Provider::Mollie->value,
            $request,
            $status,
            $outcome,
            connection: $connection,
            fanoutStatus: $fanout,
        );
    }
}
