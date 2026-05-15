<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-15: tot Phase 9 Filament-panel landt, is "Emeq-admin" een
 * config-allowlist van Consumer-IDs. Token-houders die NIET in de
 * allowlist staan krijgen 403 op admin-billing-endpoints.
 *
 * Stop met deze middleware zodra Phase 9 een `is_emeq_staff` boolean
 * op het User-model heeft en admin-tokens daadwerkelijk via Filament
 * worden uitgegeven aan staff-users i.p.v. via PATs op Consumers.
 */
final class EnsureEmeqAdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $consumer = $request->user();
        $allowlist = config('billing.admin_allowlist', []);

        if ($consumer === null || ! is_array($allowlist) || ! in_array($consumer->getKey(), $allowlist, true)) {
            return response()->json([
                'error' => 'not_admin',
                'message' => 'Token hoort niet bij een Emeq-admin-Consumer.',
            ], 403);
        }

        return $next($request);
    }
}
