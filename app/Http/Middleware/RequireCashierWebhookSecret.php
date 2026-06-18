<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Webhooks\InboundWebhookRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-11: stap-0 hard-fail guard voor /cashier/webhook-routes.
 *
 * Reden voor middleware-vorm (ipv inline-in-controller): Cashier's eigen
 * Laravel\Cashier\Http\Controllers\WebhookController is een vendor-class
 * die wij niet patchen; een middleware wrapt 'm wel.
 *
 * NIET een signature-verify: reguliere Mollie-webhooks (NIET Connect) zijn
 * UNSIGNED en gebruiken een obscured URL als auth. Onze CASHIER_WEBHOOK_SECRET
 * is dus een aanvullende laag bovenop Mollie's URL-obscurity, niet een HMAC-key.
 *
 * Audit-rij krijgt provider='cashier' (onderscheidbaar van Connect-mollie),
 * via de provider-agnostische InboundWebhookRecorder (metadata-only).
 */
class RequireCashierWebhookSecret
{
    public function __construct(private readonly InboundWebhookRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.cashier.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            $this->recorder->record('cashier', $request, 500, InboundWebhookRecorder::OUTCOME_MISCONFIGURED);

            return response()->json(['error' => 'webhook_misconfigured'], 500);
        }

        return $next($request);
    }
}
