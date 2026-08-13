<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Connections\ConnectionResource;
use App\Filament\Resources\Consumers\ConsumerResource;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard-widget: omvang van het platform langs de keten Consumer → Account → Connection.
 *
 * Staat bovenaan (sort 0): de totalen zijn het kader waarbinnen de triage-tellers van
 * {@see OperationalHealthWidget} en de per-provider-splitsing van
 * {@see ConnectionStatsWidget} gelezen worden.
 */
class PlatformScaleWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Platform';

    protected ?string $description = 'De keten Consumer → Account → Connection. Beschrijvingen tellen alleen actieve, niet-ingetrokken koppelingen.';

    protected static ?int $sort = 0;

    // Hub-omvang — niet voor boekhouder (die ziet enkel de Boekhouding-cluster).
    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'staff']) ?? false;
    }

    /**
     * Rauwe tellers — los van de presentatie zodat ze deterministisch getest kunnen worden.
     *
     * @return array{consumers: int, connected_consumers: int, accounts: int, connected_accounts: int, connections: int, active_connections: int, revoked_connections: int}
     */
    public function platformCounts(): array
    {
        return [
            'consumers' => Consumer::query()->count(),
            'connected_consumers' => Consumer::query()
                ->whereHas('connections', fn (Builder $query) => $this->scopeToActive($query))
                ->count(),
            'accounts' => Account::query()->count(),
            'connected_accounts' => Account::query()
                ->whereHas('connections', fn (Builder $query) => $this->scopeToActive($query))
                ->count(),
            'connections' => Connection::query()->count(),
            'active_connections' => $this->scopeToActive(Connection::query())->count(),
            'revoked_connections' => Connection::query()->whereNotNull('revoked_at')->count(),
        ];
    }

    protected function getStats(): array
    {
        $counts = $this->platformCounts();

        return [
            Stat::make('Consumers', (string) $counts['consumers'])
                ->description($counts['connected_consumers'].' met een actieve koppeling')
                ->descriptionIcon('heroicon-o-building-office-2')
                ->color($counts['consumers'] > 0 ? 'primary' : 'gray')
                ->url(ConsumerResource::getUrl()),

            Stat::make('Accounts', (string) $counts['accounts'])
                ->description($counts['connected_accounts'].' met een actieve koppeling')
                ->descriptionIcon('heroicon-o-users')
                ->color($counts['accounts'] > 0 ? 'primary' : 'gray')
                ->url(AccountResource::getUrl()),

            Stat::make('Connections', (string) $counts['connections'])
                ->description($counts['active_connections'].' actief · '.$counts['revoked_connections'].' revoked')
                ->descriptionIcon('heroicon-o-link')
                ->color($counts['revoked_connections'] > 0 ? 'warning' : ($counts['connections'] > 0 ? 'primary' : 'gray'))
                ->url(ConnectionResource::getUrl()),
        ];
    }

    /**
     * Filtert op de connections-tabel; het builder-model is irrelevant, zodat dit
     * zowel op `Connection::query()` als binnen een `whereHas`-subquery werkt.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopeToActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereNull('revoked_at');
    }
}
