<?php

declare(strict_types=1);

namespace App\Support;

class ProviderShowcase
{
    /** @return list<array{key:string,label:string,tagline:string,category:string,summary:string,logo:?string,brand:?string,live:bool}> */
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
                'live' => $data['live'] ?? false,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function detail(string $key): ?array
    {
        $showcase = $this->showcase();

        if (! array_key_exists($key, $showcase)) {
            return null;
        }

        return ['key' => $key, ...$showcase[$key]];
    }

    /** @return array<string, array<string, mixed>> */
    private function showcase(): array
    {
        return array_intersect_key(
            config('partner-showcase', []),
            config('hub-providers', []),
        );
    }
}
