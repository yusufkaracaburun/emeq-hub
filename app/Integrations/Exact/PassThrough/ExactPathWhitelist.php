<?php

namespace App\Integrations\Exact\PassThrough;

/**
 * Guard voor de generieke Exact pass-through: laat alleen de Exact OData-resources
 * door die de Hub daadwerkelijk ondersteunt. De lijst leeft in
 * `config('hub-providers.exact.allowed_paths')` en spiegelt de App-Center-scope-
 * matrix (docs/exact/data-security-answers.md). Lege lijst = whitelist uit.
 *
 * Matcht op resource-pad (`category/Resource`), niet op verb — verb-controle
 * blijft bij de token-abilities en Exact zelf.
 */
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

    /**
     * @return list<string>
     */
    public function allowedPaths(): array
    {
        return array_values(array_filter(
            (array) config('hub-providers.exact.allowed_paths', []),
            static fn ($path): bool => is_string($path) && $path !== '',
        ));
    }

    /**
     * Strip leading slash, query-string en OData key-predicate (`(guid'…')`),
     * lowercase — zodat `crm/Accounts`, `/crm/Accounts(guid'x')?$select=Name` en
     * `CRM/accounts` allemaal op dezelfde resource-sleutel uitkomen.
     */
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
