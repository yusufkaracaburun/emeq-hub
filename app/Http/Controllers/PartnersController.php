<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Publieke integraties-showcase. Toont de providers die de Hub ondersteunt en
 * wat ze leveren — géén tenant-data. Een provider verschijnt alleen als hij in
 * zowel config/hub-providers.php (bestaat) als config/partner-showcase.php
 * (heeft copy) staat.
 */
class PartnersController extends Controller
{
    public function index(): Response
    {
        $providers = collect($this->showcase())
            ->map(fn (array $data, string $key): array => [
                'key' => $key,
                'label' => $data['label'],
                'tagline' => $data['tagline'],
                'category' => $data['category'],
                'summary' => $data['summary'],
            ])
            ->values();

        return Inertia::render('partners/index', [
            'providers' => $providers,
        ]);
    }

    public function show(Request $request, string $provider): Response
    {
        $showcase = $this->showcase();

        abort_unless(array_key_exists($provider, $showcase), 404);

        return Inertia::render('partners/show', [
            'provider' => ['key' => $provider, ...$showcase[$provider]],
        ]);
    }

    /**
     * Showcase-content beperkt tot providers die ook echt in de Hub bestaan.
     *
     * @return array<string, array<string, mixed>>
     */
    private function showcase(): array
    {
        $registered = config('hub-providers', []);

        return array_intersect_key(
            config('partner-showcase', []),
            $registered,
        );
    }
}
