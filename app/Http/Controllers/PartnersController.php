<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ProviderShowcase;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Publieke integraties-showcase. Toont de providers die de Hub ondersteunt en
 * wat ze leveren — géén tenant-data. De content komt uit ProviderShowcase
 * (config/hub-providers.php ∩ config/partner-showcase.php).
 */
class PartnersController extends Controller
{
    public function __construct(private readonly ProviderShowcase $showcase) {}

    public function index(): Response
    {
        return Inertia::render('partners/index', [
            'providers' => $this->showcase->summaries(),
        ]);
    }

    public function show(string $provider): Response
    {
        $detail = $this->showcase->detail($provider);

        abort_if($detail === null, 404);

        return Inertia::render('partners/show', [
            'provider' => $detail,
        ]);
    }
}
