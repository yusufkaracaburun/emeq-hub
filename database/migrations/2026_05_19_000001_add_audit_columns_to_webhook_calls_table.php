<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 9 / Plan 09-01 — D-02: 4 audit-kolommen op de Spatie `webhook_calls`-tabel
 * zodat Plan 09-08 (WebhookCallResource) erop kan filteren. Additive-only:
 * bestaande rijen blijven valide (`direction` default = 'incoming';
 * `provider` + `consumer_id` nullable; `status` default = 'processed').
 * Geen wijziging aan Spatie's eigen create-migratie of model.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. `direction` — onderscheid incoming-partner-webhooks van outgoing
        //    consumer-callback-fan-outs. Default 'incoming' dekt bestaande rijen
        //    (Spatie's webhook-server schrijft typisch incoming-payloads).
        Schema::table('webhook_calls', function (Blueprint $table): void {
            $table->enum('direction', ['incoming', 'outgoing'])
                ->default('incoming')
                ->after('id');
            $table->index('direction');
        });

        // 2. `provider` — `mollie` / `snelstart` / `cashier` / toekomstige
        //    providers. Nullable zodat bestaande rijen niet gebackfilled hoeven.
        Schema::table('webhook_calls', function (Blueprint $table): void {
            $table->string('provider', 32)->nullable()->after('direction');
            $table->index('provider');
        });

        // 3. `consumer_id` — multi-tenant filter voor admin-UI. Nullable +
        //    nullOnDelete: Consumer-delete bewaart audit-rij maar nuleert FK.
        Schema::table('webhook_calls', function (Blueprint $table): void {
            $table->foreignId('consumer_id')
                ->nullable()
                ->after('provider')
                ->constrained('consumers')
                ->nullOnDelete();
        });

        // 4. `status` — pending/processed/failed voor debugging webhook-flow.
        //    Default 'processed' dekt bestaande rijen.
        Schema::table('webhook_calls', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'processed', 'failed'])
                ->default('processed')
                ->after('consumer_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_calls', function (Blueprint $table): void {
            $table->dropForeign(['consumer_id']);
            $table->dropColumn(['status', 'consumer_id', 'provider', 'direction']);
        });
        // Forward-only-policy in productie (CLAUDE.md — Migrations zijn forward-only in prod).
        // down() bestaat voor lokale migrate:fresh.
    }
};
