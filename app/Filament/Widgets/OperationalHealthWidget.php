<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Connection;
use App\Models\InboundWebhookEvent;
use App\Models\PassThroughCall;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class OperationalHealthWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Operationele status';

    protected ?string $description = 'Wat nu aandacht nodig heeft. Tijdvensters: fouten 24 uur, verlopende koppelingen 7 dagen.';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'staff']) ?? false;
    }

    /** @return array{failed_pass_throughs: int, webhook_problems: int, expiring_connections: int, pending_oauth: int} */
    public function attentionCounts(): array
    {
        return [
            'failed_pass_throughs' => self::failedPassThroughCount(),
            'webhook_problems' => self::webhookProblemCount(),
            'expiring_connections' => self::expiringConnectionCount(),
            'pending_oauth' => Connection::query()->where('status', 'pending')->count(),
        ];
    }

    public static function failedPassThroughCount(): int
    {
        return PassThroughCall::query()
            ->where('status', '>=', 400)
            ->where('created_at', '>=', now()->subDay())
            ->count();
    }

    public static function webhookProblemCount(): int
    {
        return InboundWebhookEvent::query()
            ->whereNotIn('outcome', ['processed', 'duplicate'])
            ->where('received_at', '>=', now()->subDay())
            ->count();
    }

    public static function expiringConnectionCount(): int
    {
        return Connection::query()
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays(7)])
            ->count();
    }

    protected function getStats(): array
    {
        $counts = $this->attentionCounts();

        return [
            Stat::make('Mislukte pass-throughs (24u)', (string) $counts['failed_pass_throughs'])
                ->description($counts['failed_pass_throughs'] > 0 ? 'HTTP ≥ 400 richting partner-API' : 'Geen fouten')
                ->descriptionIcon($counts['failed_pass_throughs'] > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($counts['failed_pass_throughs'] > 0 ? 'danger' : 'success')
                ->chart($this->failedPassThroughTrend()),

            Stat::make('Webhook-problemen (24u)', (string) $counts['webhook_problems'])
                ->description($counts['webhook_problems'] > 0 ? 'Inkomende webhooks niet verwerkt' : 'Alles verwerkt')
                ->descriptionIcon($counts['webhook_problems'] > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($counts['webhook_problems'] > 0 ? 'danger' : 'success'),

            Stat::make('Verlopen < 7 dagen', (string) $counts['expiring_connections'])
                ->description($counts['expiring_connections'] > 0 ? 'OAuth-tokens verlopen binnenkort' : 'Niets verloopt binnenkort')
                ->descriptionIcon($counts['expiring_connections'] > 0 ? 'heroicon-o-clock' : 'heroicon-o-check-circle')
                ->color($counts['expiring_connections'] > 0 ? 'warning' : 'success'),

            Stat::make('Pending OAuth', (string) $counts['pending_oauth'])
                ->description($counts['pending_oauth'] > 0 ? 'Niet-afgeronde handshakes' : 'Geen open handshakes')
                ->descriptionIcon($counts['pending_oauth'] > 0 ? 'heroicon-o-ellipsis-horizontal-circle' : 'heroicon-o-check-circle')
                ->color($counts['pending_oauth'] > 0 ? 'warning' : 'gray'),
        ];
    }

    /** @return list<int> */
    private function failedPassThroughTrend(): array
    {
        $perDay = PassThroughCall::query()
            ->where('status', '>=', 400)
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->pluck('created_at')
            ->groupBy(fn ($timestamp): string => Carbon::parse($timestamp)->format('Y-m-d'))
            ->map->count();

        $trend = [];
        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $trend[] = (int) ($perDay[now()->subDays($daysAgo)->format('Y-m-d')] ?? 0);
        }

        return $trend;
    }
}
