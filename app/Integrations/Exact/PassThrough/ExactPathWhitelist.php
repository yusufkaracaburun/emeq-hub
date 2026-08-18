<?php

namespace App\Integrations\Exact\PassThrough;

final class ExactPathWhitelist
{
    public function allows(string $path): bool
    {
        $allowed = $this->allowedPaths();

        if ($allowed === []) {
            return true;
        }

        $normalized = $this->normalize($path);

        if ($normalized === '') {
            return false;
        }

        foreach ($allowed as $entry) {
            $entry = $this->normalize($entry);

            if ($normalized === $entry || str_starts_with($normalized, $entry.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function allowedPaths(): array
    {
        return array_values(array_filter(
            (array) config('hub-providers.exact.allowed_paths', []),
            static fn ($path): bool => is_string($path) && $path !== '',
        ));
    }

    private function normalize(string $path): string
    {
        $path = ltrim($path, '/');

        if (($pos = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $pos);
        }

        if (($pos = strpos($path, '(')) !== false) {
            $path = substr($path, 0, $pos);
        }

        return strtolower(rtrim($path, '/'));
    }
}
