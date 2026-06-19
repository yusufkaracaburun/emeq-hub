<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source voor de publieke showcase-content. Een provider telt alleen mee
 * als hij in zowel config/hub-providers.php (bestaat) als config/partner-showcase.php
 * (heeft copy) staat. Gedeeld door HomeController en PartnersController.
 */
class ProviderShowcase
{
    /**
     * Korte samenvattingen voor grid + logo-cloud.
     *
     * @return list<array{key:string,label:string,tagline:string,category:string,summary:string,logo:?string,brand:?string}>
     */
    public function summaries(): array
    {
        return collect($this->showcase())
            ->map(fn (array $data, string $key): array => [
                'key' => $key,
                'label' => $data['label'],
                'tagline' => $data['tagline'],
                'category' => $data['category'],
                'summary' => $data['summary'],
                'logo' => $data['logo'] ?? null,
                'brand' => $data['brand'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Volledige detail-content voor één provider, of null als hij niet bestaat.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $key): ?array
    {
        $showcase = $this->showcase();

        if (! array_key_exists($key, $showcase)) {
            return null;
        }

        return ['key' => $key, ...$showcase[$key]];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function showcase(): array
    {
        return array_intersect_key(
            config('partner-showcase', []),
            config('hub-providers', []),
        );
    }
}
