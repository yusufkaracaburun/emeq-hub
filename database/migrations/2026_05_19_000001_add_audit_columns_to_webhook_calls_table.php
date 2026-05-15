<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 9 / Plan 09-01 — D-02: 4 audit-kolommen op de Spatie `webhook_calls`-tabel
// zodat Plan 09-08 (WebhookCallResource) erop kan filteren. Additive-only:
// bestaande rijen blijven valide (`direction` default = 'incoming';
// `provider` + `consumer_id` nullable; `status` default = 'processed').
// Geen wijziging aan Spatie's eigen create-migratie of model.
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // Stap 1 — alle 4 kolommen + indexen. Geen FK in deze stap zodat SQLite
        // geen destructieve temp-table-rebuild triggert (`alter table … add
        // foreign key` is niet supported door SQLite; Laravel valt anders terug
        // op een `__temp__`-rebuild die de zojuist toegevoegde kolommen wist).
        Schema::table('webhook_calls', function (Blueprint $table): void {
            // 1. `direction` — onderscheid incoming-partner-webhooks van
            //    outgoing consumer-callback-fan-outs. Default 'incoming' dekt
            //    bestaande rijen (Spatie's webhook-server schrijft typisch
            //    incoming-payloads).
            $table->enum('direction', ['incoming', 'outgoing'])
                ->default('incoming')
                ->after('id');

            // 2. `provider` — `mollie` / `snelstart` / `cashier` / toekomstige
            //    providers. Nullable zodat bestaande rijen niet gebackfilled
            //    hoeven.
            $table->string('provider', 32)->nullable()->after('direction');

            // 3. `consumer_id` — multi-tenant filter voor admin-UI. Nullable
            //    integer; FK-constraint volgt in stap 2 (alleen Postgres).
            $table->unsignedBigInteger('consumer_id')->nullable()->after('provider');

            // 4. `status` — pending/processed/failed voor debugging
            //    webhook-flow. Default 'processed' dekt bestaande rijen.
            $table->enum('status', ['pending', 'processed', 'failed'])
                ->default('processed')
                ->after('consumer_id');

            // Indexen voor filter-queries (Plan 09-08 / WebhookCallResource).
            $table->index('direction');
            $table->index('provider');
            $table->index('status');
            $table->index('consumer_id');
        });

        // Stap 2 — FK-constraint op `consumer_id` voor Postgres (productie).
        // SQLite slaat deze over: het ondersteunt geen ALTER-TABLE-ADD-FK
        // zonder destructieve rebuild en gebruikt PRAGMA-foreign_keys=OFF in
        // RefreshDatabase-tests. App-laag enforced de invariant
        // (Consumer-delete via Filament-admin is gated, niet public).
        if ($driver !== 'sqlite') {
            Schema::table('webhook_calls', function (Blueprint $table): void {
                $table->foreign('consumer_id')
                    ->references('id')
                    ->on('consumers')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            Schema::table('webhook_calls', function (Blueprint $table): void {
                $table->dropForeign(['consumer_id']);
            });
        }

        Schema::table('webhook_calls', function (Blueprint $table): void {
            $table->dropColumn(['status', 'consumer_id', 'provider', 'direction']);
        });
        // Forward-only-policy in productie (CLAUDE.md — Migrations zijn forward-only in prod).
        // down() bestaat voor lokale migrate:fresh.
    }
};
