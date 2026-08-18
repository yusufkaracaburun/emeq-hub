<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Console;

use App\Enums\Provider;
use App\Integrations\Exact\OAuth\ExactOAuthFlow;
use App\Models\Connection;
use Illuminate\Console\Command;
use Throwable;

final class BackfillUserIds extends Command
{
    protected $signature = 'exact:backfill-user-ids
                            {--force : Daadwerkelijk wegschrijven (zonder = dry-run)}';

    protected $description = 'Vul metadata.exact_user_id voor bestaande Exact-koppelingen zodat deprovisioning ze terugvindt';

    public function handle(ExactOAuthFlow $oauthFlow): int
    {
        $connections = Connection::query()
            ->where('provider', Provider::Exact)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->whereNull('metadata->exact_user_id')
            ->get();

        if ($connections->isEmpty()) {
            $this->info('Alle actieve Exact-koppelingen hebben al een exact_user_id.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn("Dry-run: {$connections->count()} koppeling(en) missen exact_user_id. Draai met --force om te vullen.");
            $this->table(['Connection', 'Account', 'Division'], $connections->map(fn (Connection $c) => [
                $c->id, $c->account_id, $c->administratie_id,
            ])->all());

            return self::SUCCESS;
        }

        $filled = 0;
        $failed = 0;

        foreach ($connections as $connection) {
            try {
                if ($oauthFlow->syncUserId($connection)) {
                    $filled++;
                    $this->line("  ✓ {$connection->id}");

                    continue;
                }

                $failed++;
                $this->warn("  ✖ {$connection->id} — /Me gaf geen UserID terug");
            } catch (Throwable $e) {
                $failed++;
                $this->warn("  ✖ {$connection->id} — {$e->getMessage()}");
            }
        }

        $this->info("Gevuld: {$filled}. Mislukt: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
