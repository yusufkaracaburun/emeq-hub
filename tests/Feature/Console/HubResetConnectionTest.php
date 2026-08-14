<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\Consumer;
use App\Models\IdempotencyKey;
use App\Models\ProviderEntityLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Exception\RuntimeException;
use Tests\TestCase;

/**
 * `hub:reset-connection` ruimt de Hub-eigen state op die `exact:purge-test-data`
 * (Exact-only) laat staan: idempotency-claims, entity-links en de relatie-mirror.
 * Zonder deze opruiming gaf herboeken na een Exact-purge nog steeds
 * `422 idempotency_key_reuse`.
 */
class HubResetConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function connectionWithConsumer(): Connection
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        return Connection::factory()->forExact()->for($account)->create();
    }

    private function idempotencyKeyFor(Connection $connection, string $key): IdempotencyKey
    {
        return IdempotencyKey::query()->create([
            'consumer_id' => $connection->account->consumer_id,
            'key' => $key,
            'method' => 'POST',
            'path' => 'v1/accounting/documents',
            'state' => IdempotencyKey::STATE_COMPLETED,
            'response_status' => 201,
            'created_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }

    private function accountingRef(Connection $connection, string $kind, string $code): ConnectionAccountingRef
    {
        return ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->id,
            'kind' => $kind,
            'code' => $code,
            'native_id' => $code,
        ]);
    }

    public function test_requires_a_connection_argument(): void
    {
        // Geen default op het argument → Artisan weigert het commando zelf te starten
        // ("geen connection meegegeven = falen, geen 'alles'").
        $this->expectException(RuntimeException::class);

        $this->artisan('hub:reset-connection');
    }

    public function test_fails_for_an_unknown_connection(): void
    {
        $this->artisan('hub:reset-connection', ['connection' => 999999])->assertFailed();
    }

    public function test_accepts_the_public_id(): void
    {
        $connection = $this->connectionWithConsumer();

        $this->artisan('hub:reset-connection', ['connection' => $connection->public_id])
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();
    }

    public function test_dry_run_reports_counts_and_deletes_nothing(): void
    {
        $connection = $this->connectionWithConsumer();
        $this->idempotencyKeyFor($connection, 'key-1');
        ProviderEntityLink::factory()->for($connection)->create();
        $this->accountingRef($connection, ConnectionAccountingRef::KIND_RELATION, 'cust-1');
        $this->accountingRef($connection, ConnectionAccountingRef::KIND_GL, '8000');

        $this->artisan('hub:reset-connection', ['connection' => $connection->id])
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertSame(1, IdempotencyKey::count());
        $this->assertSame(1, ProviderEntityLink::count());
        $this->assertSame(2, ConnectionAccountingRef::count());
    }

    public function test_force_without_interaction_deletes_the_scoped_rows(): void
    {
        $connection = $this->connectionWithConsumer();
        $this->idempotencyKeyFor($connection, 'key-1');
        ProviderEntityLink::factory()->for($connection)->create();
        $this->accountingRef($connection, ConnectionAccountingRef::KIND_RELATION, 'cust-1');

        $this->artisan('hub:reset-connection', [
            'connection' => $connection->id,
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertSame(0, IdempotencyKey::count());
        $this->assertSame(0, ProviderEntityLink::count());
        $this->assertSame(0, ConnectionAccountingRef::count());
    }

    /**
     * De kernafbakening: gl/vat/journal/cost_center/cost_unit zijn echte Exact-
     * referentiedata, geen test-vervuiling — die moeten na een reset blijven staan.
     */
    public function test_only_relation_kind_refs_are_deleted_the_rest_survives(): void
    {
        $connection = $this->connectionWithConsumer();
        $this->accountingRef($connection, ConnectionAccountingRef::KIND_RELATION, 'cust-1');
        $this->accountingRef($connection, ConnectionAccountingRef::KIND_GL, '8000');
        $this->accountingRef($connection, ConnectionAccountingRef::KIND_VAT, '21');
        $this->accountingRef($connection, ConnectionAccountingRef::KIND_JOURNAL, '70');
        $this->accountingRef($connection, ConnectionAccountingRef::KIND_COST_CENTER, 'ADMIN');
        $this->accountingRef($connection, ConnectionAccountingRef::KIND_COST_UNIT, 'PROJ-X');

        $this->artisan('hub:reset-connection', [
            'connection' => $connection->id,
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertSame(0, ConnectionAccountingRef::query()->where('kind', ConnectionAccountingRef::KIND_RELATION)->count());
        $this->assertSame(5, ConnectionAccountingRef::count());
        $this->assertNotNull(ConnectionAccountingRef::query()->where('kind', ConnectionAccountingRef::KIND_GL)->first());
        $this->assertNotNull(ConnectionAccountingRef::query()->where('kind', ConnectionAccountingRef::KIND_VAT)->first());
        $this->assertNotNull(ConnectionAccountingRef::query()->where('kind', ConnectionAccountingRef::KIND_JOURNAL)->first());
        $this->assertNotNull(ConnectionAccountingRef::query()->where('kind', ConnectionAccountingRef::KIND_COST_CENTER)->first());
        $this->assertNotNull(ConnectionAccountingRef::query()->where('kind', ConnectionAccountingRef::KIND_COST_UNIT)->first());
    }

    public function test_provider_entity_links_of_another_connection_are_untouched(): void
    {
        $connection = $this->connectionWithConsumer();
        $other = $this->connectionWithConsumer();
        ProviderEntityLink::factory()->for($connection)->create();
        ProviderEntityLink::factory()->for($other)->create();

        $this->artisan('hub:reset-connection', [
            'connection' => $connection->id,
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertSame(0, ProviderEntityLink::query()->where('connection_id', $connection->id)->count());
        $this->assertSame(1, ProviderEntityLink::query()->where('connection_id', $other->id)->count());
    }

    /**
     * `idempotency_keys` is consumer-scoped, niet connection-scoped (het schema kent
     * geen connection-kolom). Voor een consumer met precies één connection is dat
     * effectief hetzelfde; met meerdere connections raakt de reset ze allemaal — de
     * dry-run moet dat expliciet melden vóórdat --force iets verwijdert.
     */
    public function test_idempotency_keys_are_consumer_scoped_and_the_ambiguity_is_disclosed(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forExact()->for($account)->create();
        $otherConnection = Connection::factory()->forMollie()->for($account)->create();

        $this->idempotencyKeyFor($connection, 'key-1');

        $this->artisan('hub:reset-connection', ['connection' => $connection->id])
            ->expectsOutputToContain((string) $otherConnection->public_id)
            ->assertSuccessful();
    }

    public function test_idempotency_keys_of_an_unrelated_consumer_are_never_touched(): void
    {
        $connection = $this->connectionWithConsumer();
        $unrelated = $this->connectionWithConsumer();
        $this->idempotencyKeyFor($connection, 'key-1');
        $this->idempotencyKeyFor($unrelated, 'key-1'); // zelfde key, andere consumer — geen botsing

        $this->artisan('hub:reset-connection', [
            'connection' => $connection->id,
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertSame(0, IdempotencyKey::query()->where('consumer_id', $connection->account->consumer_id)->count());
        $this->assertSame(1, IdempotencyKey::query()->where('consumer_id', $unrelated->account->consumer_id)->count());
    }

    public function test_interactive_confirmation_names_the_connection_and_a_decline_deletes_nothing(): void
    {
        $connection = $this->connectionWithConsumer();
        ProviderEntityLink::factory()->for($connection)->create();

        $this->artisan('hub:reset-connection', [
            'connection' => $connection->id,
            '--force' => true,
        ])
            ->expectsConfirmation(
                "Weet je zeker dat je alle Hub-state voor connection '{$connection->public_id}' (exact) wilt verwijderen? Dit kan niet ongedaan worden gemaakt.",
                'no',
            )
            ->assertFailed();

        $this->assertSame(1, ProviderEntityLink::count());
    }

    public function test_interactive_confirmation_accepted_deletes(): void
    {
        $connection = $this->connectionWithConsumer();
        ProviderEntityLink::factory()->for($connection)->create();

        $this->artisan('hub:reset-connection', [
            'connection' => $connection->id,
            '--force' => true,
        ])
            ->expectsConfirmation(
                "Weet je zeker dat je alle Hub-state voor connection '{$connection->public_id}' (exact) wilt verwijderen? Dit kan niet ongedaan worden gemaakt.",
                'yes',
            )
            ->assertSuccessful();

        $this->assertSame(0, ProviderEntityLink::count());
    }
}
