<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\WebhookClient\Models\WebhookCall;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-11: stap-0 hard-fail guard voor /cashier/webhook-routes.
 * Analoog aan Phase 5a's MollieWebhookController regel 37-46.
 *
 * Reden voor middleware-vorm (ipv inline-in-controller): Cashier's eigen
 * Laravel\Cashier\Http\Controllers\WebhookController is een vendor-class
 * die wij niet patchen; een middleware wrapt 'm wel.
 *
 * NIET een signature-verify: reguliere Mollie-webhooks (NIET Connect) zijn
 * UNSIGNED en gebruiken een obscured URL als auth. Onze CASHIER_WEBHOOK_SECRET
 * is dus een aanvullende laag bovenop Mollie's URL-obscurity, niet een HMAC-key.
 *
 * Audit-rij krijgt name='cashier' (onderscheidbaar van Phase 5a name='mollie').
 */
class RequireCashierWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.cashier.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            $this->auditFailedWebhook($request, 'webhook_secret_not_configured');

            return response()->json([
                'error' => 'webhook_misconfigured',
            ], 500);
        }

        return $next($request);
    }

    private function auditFailedWebhook(Request $request, string $exception): void
    {
        WebhookCall::create([
            'name' => 'cashier',
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'payload' => $request->json()->all() ?: ['_raw' => substr($request->getContent(), 0, 1000)],
            'exception' => $exception,
        ]);
    }
}
