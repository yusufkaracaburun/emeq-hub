<?php

namespace App\Console\Commands;

use App\Models\Connection;
use Illuminate\Console\Command;

class PruneOAuthPendingConnections extends Command
{
    protected $signature = 'oauth:prune-pending
                            {--dry-run : Toon welke rows verwijderd zouden worden zonder ze te raken}';

    protected $description = 'Ruim expired pending OAuth-Connections op (status=pending AND oauth_state_expires_at < now AND geen tokens)';

    public function handle(): int
    {
        $query = Connection::query()
            ->where('status', 'pending')
            ->whereNull('access_token')
            ->where('oauth_state_expires_at', '<', now());

        if ($this->option('dry-run')) {
            $count = $query->count();
            $this->info("Dry-run: {$count} pending Connection(s) zouden worden verwijderd.");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Verwijderd: {$deleted} expired pending Connection(s).");

        return self::SUCCESS;
    }
}
