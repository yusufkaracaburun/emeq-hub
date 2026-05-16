<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Connection;
use App\Support\ProviderCredentialDescriptor;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard-widget: per-provider connection-counts opgesplitst naar actief / revoked.
 *
 * Provider-set komt uit `config/hub-providers.php` via ProviderCredentialDescriptor::all(),
 * dus een nieuwe provider verschijnt automatisch zonder widget-wijziging (D-04 invariant).
 */
class ConnectionStatsWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Koppelingen per SDK';

    protected ?string $description = 'Aantal actieve OAuth- en credential-koppelingen per provider, inclusief eventueel revoked tokens.';

    protected function getStats(): array
    {
        $stats = [];

        foreach (ProviderCredentialDescriptor::all() as $descriptor) {
            $base = Connection::query()->where('provider', $descriptor->key);
            $active = (clone $base)->whereNull('revoked_at')->count();
            $revoked = (clone $base)->whereNotNull('revoked_at')->count();
            $total = $active + $revoked;

            $stats[] = Stat::make(ucfirst($descriptor->key), (string) $total)
                ->description($active.' actief · '.$revoked.' revoked')
                ->descriptionIcon($revoked > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($revoked > 0 ? 'warning' : 'success');
        }

        return $stats;
    }
}
