<?php

declare(strict_types=1);

namespace App\Integrations\Errors;

use App\Integrations\Contracts\MapsUpstreamExceptions;
use Throwable;

/**
 * Welke {@see MapsUpstreamExceptions} hoort bij welke provider. Spiegel van
 * `OAuthFlowRegistry` en `AccountingTargetRegistry`.
 *
 * Bestaat omdat provider-neutrale code anders een providerspecifieke mapper moet
 * kiezen op importniveau — en dat gebeurde ook: de accounting-runner en de
 * canonieke lees-endpoints importeerden allebei Exact's mapper. Een Moneybird-
 * exception matcht daar niets in en viel door naar "onbekende fout", waardoor een
 * inhoudelijke afwijzing (422 met veldinformatie) als 502 bij de consumer landde.
 */
final class UpstreamErrorMapperRegistry
{
    /** @var array<string, class-string<MapsUpstreamExceptions>> */
    private array $mappers = [];

    /**
     * @param  class-string<MapsUpstreamExceptions>  $mapper
     */
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

    /**
     * Een provider zonder mapper valt terug op de neutrale vorm in plaats van te
     * gooien: een ontbrekende registratie mag een partner-fout niet in een 500
     * veranderen. Dat het ontbreekt hoort in CI op te vallen, niet in productie —
     * daarvoor is de volledigheidstest over `Provider::cases()`.
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string}
     */
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
