<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Provider;
use App\Models\Connection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Branded HTML-landing na een partner-OAuth-callback (PRG). De callbacks in
 * api.php draaien onder de stateless `api`-middleware (geen sessie), dus de
 * redirect hierheen gebeurt met een tijdelijk-getekende URL (`signed`) — dat is
 * tamper-proof en voorkomt enumeratie van Connection-ids.
 */
class OAuthLandingController extends Controller
{
    public function connected(Connection $connection): View
    {
        return view('oauth.result', [
            'success' => true,
            'provider' => $connection->provider,
            'connection' => $connection,
            'reason' => null,
            'backUrl' => $this->backUrl(),
        ]);
    }

    public function failed(Request $request): View
    {
        $validated = $request->validate([
            'provider' => ['required', 'string'],
            'reason' => ['nullable', 'string'],
        ]);

        return view('oauth.result', [
            'success' => false,
            'provider' => Provider::tryFrom($validated['provider']),
            'connection' => null,
            'reason' => $validated['reason'] ?? 'unknown_error',
            'backUrl' => $this->backUrl(),
        ]);
    }

    private function backUrl(): string
    {
        return Route::has('filament.admin.resources.connections.index')
            ? route('filament.admin.resources.connections.index')
            : url('/admin');
    }
}
