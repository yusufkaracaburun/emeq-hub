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

/**
 * Mollie Connect webhook ingress.
 *
 * Flow per 07-CONTEXT.md D-18 (refactor bovenop 05a D-08):
 *  0. Hard-fail guard: platform-secret moet geconfigureerd zijn
 *  1. Signature-verify (X-Mollie-Signature, HMAC-SHA256, platform-secret)
 *  2. Connection-lookup (provider=mollie, niet revoked)
 *  3. Payload-id-check
 *  4. WebhookPayloadRouter::routeFor() — id-prefix dispatch
 *  5. Audit via de provider-agnostische InboundWebhookRecorder (metadata-only,
 *     `inbound_webhook_events`) — alleen als result.shouldAudit()
 *  6. ForwardWebhookToConsumerJob-dispatch (alleen als result.shouldFanOut())
 *  7. 202 Accepted (of 400 op anti-spoof-fail)
 *
 * Géén idempotency-dedup: Mollie vuurt meerdere legitieme webhooks voor dezelfde
 * payment-id (statuswijziging) → event_id blijft NULL (de handler re-fetcht en is
 * van zichzelf idempotent).
 */
class MollieWebhookController extends Controller
{
    public function __construct(
        private readonly WebhookPayloadRouter $router,
        private readonly InboundWebhookRecorder $recorder,
    ) {}

    public function __invoke(Request $request, int $connection_id): JsonResponse
    {
        // 0. Hard-fail guard: platform-secret moet geconfigureerd zijn.
        $secret = config('mollie.webhook.secret');
        if (! is_string($secret) || $secret === '') {
            $this->recorder->record(Provider::Mollie->value, $request, 500, InboundWebhookRecorder::OUTCOME_MISCONFIGURED);

            return response()->json(['error' => 'webhook_misconfigured'], 500);
        }

        // 1. Signature-verify
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

        // 2. Connection-lookup
        $connection = Connection::query()
            ->where('id', $connection_id)
            ->where('provider', Provider::Mollie->value)
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null) {
            $this->recorder->record(Provider::Mollie->value, $request, 410, InboundWebhookRecorder::OUTCOME_UNKNOWN_TENANT);

            return response()->json(['error' => 'connection_gone'], 410);
        }

        // 3. Payload-id-check
        $payload = $request->json()->all();
        if (! is_array($payload) || ! isset($payload['id']) || ! is_string($payload['id'])) {
            $this->recorder->record(Provider::Mollie->value, $request, 400, InboundWebhookRecorder::OUTCOME_MALFORMED, connection: $connection);

            return response()->json(['error' => 'missing_id'], 400);
        }

        // 4. Resource-type-routing → Hub-state-update
        $result = $this->router->routeFor($payload['id'], $payload, $connection);

        // 5. Audit
        if ($result->shouldAudit()) {
            $this->auditResult($request, $connection, $result);
        }

        // 6. Anti-spoof-fail → 400 + geen fan-out
        if ($result->status === 'anti_spoof_failed') {
            return response()->json(['error' => 'resource_ownership_failed'], 400);
        }

        // 7. Fan-out
        if ($result->shouldFanOut()) {
            // `queue: null` houdt de Mollie-fan-out bewust op de default-queue: dat is
            // een andere Horizon-supervisor dan `webhooks` (5 processen i.p.v. 10) en
            // die keuze stond al zo (b0c612c). Verplaatsen is een capaciteitsbesluit.
            ForwardWebhookToConsumerJob::dispatch(Provider::Mollie, $connection, $payload, null, null);
        }

        // 8. 202 Accepted
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
