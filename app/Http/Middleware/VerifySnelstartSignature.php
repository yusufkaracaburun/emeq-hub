<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PassThroughCall;
use App\Webhooks\SnelstartSignatureVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HMAC-gate voor /webhooks/snelstart-ingress (Phase 5c).
 *
 * CONTEXT-locked gedrag:
 *  - secret ontbreekt → 500 + audit-rij `webhook_secret_not_configured`
 *    (analoog Mollie D-08 stap 1, target = pass_through_calls per 05c-CONTEXT)
 *  - invalid signature → 401 + lege body + GEEN audit (anti-amplification, T-05c-05)
 *  - valid signature → next handler
 *
 * Header-naam, algo en rotation-window zijn config-driven via
 * services.snelstart.webhook_*; partner-respons mag defaults wijzigen zonder
 * code-deploy.
 */
final class VerifySnelstartSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $primary = config('services.snelstart.webhook_secret');
        $secondary = config('services.snelstart.webhook_secret_next');
        $headerName = (string) config('services.snelstart.webhook_signature_header', 'X-SnelStart-Signature');
        $algo = (string) config('services.snelstart.webhook_signature_algo', 'sha256');

        $secrets = array_values(array_filter(
            [$primary, $secondary],
            static fn (?string $secret): bool => is_string($secret) && $secret !== '',
        ));

        if ($secrets === []) {
            PassThroughCall::create([
                'direction' => 'inbound',
                'provider' => 'snelstart',
                'method' => $request->getMethod(),
                'path' => $request->path(),
                'status' => 500,
                'duration_ms' => 0,
                'upstream_error' => 'webhook_secret_not_configured',
            ]);

            return response('', 500);
        }

        $rawBody = $request->getContent();
        $headerValue = $request->header($headerName);

        $valid = SnelstartSignatureVerifier::verify($rawBody, $headerValue, $secrets, $algo);

        if (! $valid) {
            // Anti-amplification: geen audit-rij op invalid-pad (CONTEXT decision + T-05c-05).
            return response('', 401);
        }

        return $next($request);
    }
}
