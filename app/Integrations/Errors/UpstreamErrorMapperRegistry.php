<?php

declare(strict_types=1);

namespace App\Integrations\Errors;

use App\Integrations\Contracts\MapsUpstreamExceptions;
use Throwable;

final class UpstreamErrorMapperRegistry
{
    /** @var array<string, class-string<MapsUpstreamExceptions>> */
    private array $mappers = [];

    /** @param  class-string<MapsUpstreamExceptions>  $mapper */
    public function register(string $provider, string $mapper): void
    {
        $this->mappers[$provider] = $mapper;
    }

    public function supports(string $provider): bool
    {
        return isset($this->mappers[$provider]);
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->mappers);
    }

    /** @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string} */
    public function map(string $provider, Throwable $exception): array
    {
        $mapper = $this->mappers[$provider] ?? null;

        if ($mapper === null) {
            return [
                'status' => 502,
                'body' => [
                    'error' => 'upstream_error',
                    'message' => 'Unexpected upstream failure',
                    'upstream_status' => 0,
                    'upstream_detail' => 'unknown',
                ],
                'headers' => [],
                'short_code' => 'unmapped_provider',
            ];
        }

        return $mapper::mapException($exception);
    }
}
