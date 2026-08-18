<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Provider;
use App\Models\Connection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class OAuthLandingController extends Controller
{
    public function connected(Connection $connection): View
    {
        $returnUrl = $connection->oauth_return_url;

        return view('oauth.result', [
            'success' => true,
            'provider' => $connection->provider,
            'connection' => $connection,
            'reason' => null,
            'backUrl' => $returnUrl ?? $this->hubBackUrl(),
            'isConsumerReturn' => $returnUrl !== null,
        ]);
    }

    public function failed(Request $request): View
    {
        $validated = $request->validate([
            'provider' => ['required', 'string'],
            'reason' => ['nullable', 'string'],
            'return_url' => ['nullable', 'url'],
        ]);

        $returnUrl = $validated['return_url'] ?? null;

        return view('oauth.result', [
            'success' => false,
            'provider' => Provider::tryFrom($validated['provider']),
            'connection' => null,
            'reason' => $validated['reason'] ?? 'unknown_error',
            'backUrl' => $returnUrl ?? $this->hubBackUrl(),
            'isConsumerReturn' => $returnUrl !== null,
        ]);
    }

    private function hubBackUrl(): string
    {
        return Route::has('filament.admin.resources.connections.index')
            ? route('filament.admin.resources.connections.index')
            : url('/admin');
    }
}
