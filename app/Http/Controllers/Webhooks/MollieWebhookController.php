<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ForwardMollieWebhookToConsumer;
use App\Models\Connection;
use App\Webhooks\Mollie\WebhookHandlerResult;
use App\Webhooks\Mollie\WebhookPayloadRouter;
use Emeq\MollieApi\Webhooks\MollieWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\InvalidSignatureException;
use Spatie\WebhookClient\Models\WebhookCall;

/**
 * Mollie Connect webhook ingress.
 *
 * Flow per 07-CONTEXT.md D-18 (refactor bovenop 05a D-08):
 *  0. Hard-fail guard: platform-secret moet geconfigureerd zijn
 *  1. Signature-verify (X-Mollie-Signature, HMAC-SHA256, platform-secret)
 *  2. Connection-lookup (provider=mollie, niet revoked)
 *  3. Payload-id-check
 *  4. WebhookPayloadRouter::routeFor() — id-prefix dispatch naar
 *     PaymentWebhookHandler / SubscriptionWebhookHandler / skip-no-op
 *  5. Spatie webhook_calls-audit (alleen als result.shouldAudit())
 *  6. ForwardMollieWebhookToConsumer-dispatch (alleen als result.shouldFanOut())
 *  7. 202 Accepted (of 400 op anti-spoof-fail)
 *
 * D-31 invariant: default-pad (`tr_*` zonder subscriptionId) hergebruikt
 * Phase-5a-flow exact. Bestaande `MollieWebhookSignatureTest`,
 * `MollieWebhookAntiSpoofingTest` en `MollieWebhookFanOutTest` blijven
 * 1:1 groen zonder fixture-aanpassingen.
 */
class MollieWebhookController extends Controller
{
    public function __construct(
        private readonly WebhookPayloadRouter $router,
    ) {}

    public function __invoke(Request $request, int $connection_id): JsonResponse
    {
        // 0. Hard-fail guard: platform-secret moet geconfigureerd zijn.
        // Anders accepteert MollieWebhookSignature::verify elke HMAC die met
        // '' berekend is — open ingress bij vergeten env-var (D-08 stap 1,
        // verificatie-gap CR-02 / threat T-05a-06).
        $secret = config('services.mollie.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            $this->auditFailedWebhook($request, 'webhook_secret_not_configured');

            return response()->json(['error' => 'webhook_misconfigured'], 500);
        }

        // 1. Signature-verify
        try {
            $valid = MollieWebhookSignature::verify($request, $secret);
        } catch (InvalidSignatureException $e) {
            $this->auditFailedWebhook($request, "invalid_signature: {$e->getMessage()}");

            return response()->json(['error' => 'invalid_signature'], 400);
        }
        if (! $valid) {
            $this->auditFailedWebhook($request, 'missing_signature_header');

            return response()->json(['error' => 'missing_signature'], 400);
        }

        // 2. Connection-lookup
        $connection = Connection::query()
            ->where('id', $connection_id)
            ->where('provider', 'mollie')
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null) {
            $this->auditFailedWebhook($request, 'unknown_or_revoked_connection');

            return response()->json(['error' => 'connection_gone'], 410);
        }

        // 3. Payload-id-check
        $payload = $request->json()->all();
        if (! is_array($payload) || ! isset($payload['id']) || ! is_string($payload['id'])) {
            $this->auditFailedWebhook($request, 'missing_payload_id');

            return response()->json(['error' => 'missing_id'], 400);
        }

        // 4. Resource-type-routing (D-15) → Hub-state-update (D-18 stap 4)
        $result = $this->router->routeFor($payload['id'], $payload, $connection);

        // 5. Audit (D-18 stap 5)
        if ($result->shouldAudit()) {
            $this->auditWebhook($request, $payload, $result);
        }

        // 6. Anti-spoof-fail → 400 + geen fan-out (D-31; MollieWebhookAntiSpoofingTest contract)
        if ($result->status === 'anti_spoof_failed') {
            return response()->json(['error' => 'resource_ownership_failed'], 400);
        }

        // 7. Fan-out (D-18 stap 6) — blijft via dezelfde job-signature uit Phase 5a
        if ($result->shouldFanOut()) {
            ForwardMollieWebhookToConsumer::dispatch($connection, $payload);
        }

        // 8. 202 Accepted
        return response()->json(['status' => 'accepted'], 202);
    }

    /**
     * Schrijft een audit-rij voor een succesvolle/skipped webhook. Behoudt
     * de Phase-5a-shape: `exception` is null bij `ok`, gevuld met
     * `spoof_check_failed: ...` bij anti-spoof-fail (matched
     * `MollieWebhookAntiSpoofingTest`), of met de skip-reason bij
     * `mdt_*`-no-op zodat diagnose mogelijk blijft.
     *
     * @param  array<string, mixed>  $payload
     */
    private function auditWebhook(Request $request, array $payload, WebhookHandlerResult $result): void
    {
        $attributes = [
            'name' => 'mollie',
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'payload' => $payload,
        ];

        if ($result->status === 'anti_spoof_failed') {
            $attributes['exception'] = 'spoof_check_failed: '.($result->reason ?? '');
        } elseif ($result->status === 'skip' && $result->reason !== null) {
            $attributes['exception'] = 'skipped: '.$result->reason;
        }

        WebhookCall::create($attributes);
    }

    private function auditFailedWebhook(Request $request, string $exception): void
    {
        WebhookCall::create([
            'name' => 'mollie',
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'payload' => $request->json()->all() ?: ['_raw' => substr($request->getContent(), 0, 1000)],
            'exception' => $exception,
        ]);
    }
}
