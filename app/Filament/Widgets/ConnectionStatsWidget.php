<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Connection;
use App\Support\ProviderCredentialDescriptor;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ConnectionStatsWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Koppelingen per SDK';

    protected ?string $description = 'Aantal actieve OAuth- en credential-koppelingen per provider, inclusief eventueel revoked tokens.';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'staff']) ?? false;
    }

    protected function getStats(): array
    {
        $stats = [];

        foreach (ProviderCredentialDescriptor::all() as $descriptor) {
            $base = Connection::query()->where('provider', $descriptor->key);
            $active = (clone $base)->where('status', 'active')->whereNull('revoked_at')->count();
            $pending = (clone $base)->where('status', 'pending')->count();
            $revoked = (clone $base)->whereNotNull('revoked_at')->count();
            $total = $active + $pending + $revoked;

            $parts = [$active.' actief'];
            if ($pending > 0) {
                $parts[] = $pending.' pending';
            }
            $parts[] = $revoked.' revoked';

            $stats[] = Stat::make(ucfirst($descriptor->key), (string) $total)
                ->description(implode(' · ', $parts))
                ->descriptionIcon($revoked > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($revoked > 0 ? 'warning' : 'success');
        }

        return $stats;
    }
}
