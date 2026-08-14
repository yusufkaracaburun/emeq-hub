<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\IdempotencyKey;
use App\Models\ProviderEntityLink;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ruimt de Hub-eigen boekhoud-state voor één Connection op — het vervolg op
 * `exact:purge-test-data`, dat alleen de Exact-kant opruimt. Zonder deze stap
 * blijft de Hub het document als geboekt kennen (`idempotency_keys`,
 * `provider_entity_links`) en geeft herboeken `422 idempotency_key_reuse`, ook
 * ná een volledige Exact-purge.
 *
 * Provider-agnostisch: `provider_entity_links` en `connection_accounting_refs` zijn
 * `connection_id`-gescoped. `idempotency_keys` kent geen connection-kolom en is
 * `consumer_id`-gescoped — bij meer dan één connection per consumer raakt dat dus
 * ook andere connections; `handle()` meldt dat expliciet vóór een `--force`-run.
 *
 * `connection_accounting_refs` is de enige tabel met een deel-afbakening: alleen
 * `kind = relation` is test-vervuiling (geleerde koppelingen op een consumer-
 * gegenereerd `external_id`). `gl`/`vat`/`journal`/`cost_center`/`cost_unit` zijn de
 * gemirrorde Exact-referentiedata — die overleven een reset.
 */
final class HubResetConnection extends Command
{
    protected $signature = 'hub:reset-connection
                            {connection : Connection-ID (numeriek) of public_id (con_…)}
                            {--force : Daadwerkelijk verwijderen (zonder = dry-run)}';

    protected $description = 'Verwijder de Hub-eigen boekhoud-state (idempotency-claims, entity-links, relatie-mirror) voor één Connection';

    public function handle(): int
    {
        $connection = $this->resolveConnection((string) $this->argument('connection'));

        if ($connection === null) {
            $this->error("Connection '{$this->argument('connection')}' niet gevonden.");

            return self::FAILURE;
        }

        $consumerId = $connection->account->consumer_id;
        $otherConnections = Connection::query()
            ->whereHas('account', fn (Builder $query) => $query->where('consumer_id', $consumerId))
            ->where('id', '!=', $connection->id)
            ->get(['id', 'public_id', 'provider']);

        $plan = [
            'idempotency_keys' => [
                'scope' => "consumer_id={$consumerId}",
                'query' => IdempotencyKey::query()->where('consumer_id', $consumerId),
            ],
            'provider_entity_links' => [
                'scope' => "connection_id={$connection->id}",
                'query' => ProviderEntityLink::query()->where('connection_id', $connection->id),
            ],
            'connection_accounting_refs (kind=relation)' => [
                'scope' => "connection_id={$connection->id} AND kind=relation",
                'query' => ConnectionAccountingRef::query()
                    ->where('connection_id', $connection->id)
                    ->where('kind', ConnectionAccountingRef::KIND_RELATION),
            ],
        ];

        $this->info("Connection {$connection->public_id} (#{$connection->id}) — provider {$connection->provider->value}");
        $this->newLine();

        foreach ($plan as $label => $entry) {
            $count = (clone $entry['query'])->count();
            $this->line("<comment>{$label}</comment> — scope: {$entry['scope']}");
            $this->line("  {$count} rij(en) zouden worden verwijderd.");
        }

        $retained = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->id)
            ->where('kind', '!=', ConnectionAccountingRef::KIND_RELATION)
            ->count();
        $this->line("  ({$retained} gl/vat/journal/cost_center/cost_unit-rij(en) blijven altijd staan — echte Exact-referentiedata.)");
        $this->newLine();

        if ($otherConnections->isNotEmpty()) {
            $this->warn("Let op: consumer #{$consumerId} heeft {$otherConnections->count()} andere connection(s). `idempotency_keys` kent geen connection-kolom (consumer-scoped) — ALLE idempotency-sleutels van deze consumer worden meegeteld/verwijderd, ook die van:");
            foreach ($otherConnections as $other) {
                $this->line("    - {$other->public_id} ({$other->provider->value})");
            }
            $this->newLine();
        }

        if (! $this->option('force')) {
            $this->warn('DRY-RUN — niks verwijderd. Draai met --force om uit te voeren.');

            return self::SUCCESS;
        }

        if ($this->input->isInteractive()) {
            $confirmed = $this->confirm(
                "Weet je zeker dat je alle Hub-state voor connection '{$connection->public_id}' ({$connection->provider->value}) wilt verwijderen? Dit kan niet ongedaan worden gemaakt.",
                false,
            );

            if (! $confirmed) {
                $this->warn('Geannuleerd — niks verwijderd.');

                return self::FAILURE;
            }
        } else {
            $this->line('Non-interactief (--no-interaction): bevestigingsprompt overgeslagen.');
        }

        $deleted = DB::transaction(function () use ($plan): array {
            $tally = [];
            foreach ($plan as $label => $entry) {
                $tally[$label] = $entry['query']->delete();
            }

            return $tally;
        });

        $this->newLine();
        $this->info('Klaar — verwijderd:');
        foreach ($deleted as $label => $count) {
            $this->line("  {$label}: {$count}");
        }

        return self::SUCCESS;
    }

    private function resolveConnection(string $identifier): ?Connection
    {
        if (str_starts_with($identifier, Connection::PUBLIC_ID_PREFIX)) {
            return Connection::query()->where('public_id', $identifier)->first();
        }

        return Connection::find($identifier);
    }
}
