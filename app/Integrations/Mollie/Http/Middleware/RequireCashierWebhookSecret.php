<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\Middleware;

use App\Integrations\Webhooks\InboundWebhookRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
