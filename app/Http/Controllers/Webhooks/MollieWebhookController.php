<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ForwardMollieWebhookToConsumer;
use App\Models\Connection;
use App\Mollie\MollieConnectionContext;
use Emeq\MollieApi\Facades\Mollie;
use Emeq\MollieApi\Webhooks\MollieWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\InvalidSignatureException;
use Spatie\WebhookClient\Models\WebhookCall;
use Throwable;

/**
 * Mollie Connect webhook ingress.
 *
 * Flow per 05a-CONTEXT.md D-08:
 *  1. Signature-verify (X-Mollie-Signature, HMAC-SHA256, platform-secret)
 *  2. Connection-lookup (provider=mollie, niet revoked)
 *  3. Payload-id-check
 *  4. Anti-spoofing: fetch resource via deze Connection's access_token
 *  5. Inkomend audit naar Spatie's webhook_calls
 *  6. Fan-out via ForwardMollieWebhookToConsumer
 *  7. 202 Accepted
 *
 * v0.2-aanname: alle webhook-id's zijn Payment-id's (tr_*). Subscriptions/Refunds
 * triggeren ook een Payment-event waardoor de id geldig is. v0.3+ moet
 * resource-type-detectie via id-prefix toevoegen voor edge-cases (tr_/sub_/re_).
 */
class MollieWebhookController extends Controller
{
    public function __invoke(Request $request, int $connection_id): JsonResponse
    {
        // 1. Signature-verify
        try {
            $valid = MollieWebhookSignature::verify(
                $request,
                (string) config('services.mollie.webhook_secret'),
            );
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

        // 4. Anti-spoofing: bind context + fetch resource
        app(MollieConnectionContext::class)->set($connection);
        try {
            Mollie::client()->payments->get($payload['id']);
        } catch (Throwable $e) {
            $this->auditFailedWebhook($request, 'spoof_check_failed: '.$e->getMessage());

            return response()->json(['error' => 'resource_ownership_failed'], 400);
        }

        // 5. Inkomend audit
        WebhookCall::create([
            'name' => 'mollie',
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'payload' => $payload,
        ]);

        // 6. Fan-out
        ForwardMollieWebhookToConsumer::dispatch($connection, $payload);

        // 7. 202 Accepted
        return response()->json(['status' => 'accepted'], 202);
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
