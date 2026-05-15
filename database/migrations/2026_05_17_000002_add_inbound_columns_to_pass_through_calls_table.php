<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Nullable maken van tenant-FKs zodat een inbound webhook met onbekende
        //    administratieId een audit-rij kan schrijven (CONTEXT.md decision
        //    "Onbekende administratieId" + "Audit-tabel reuse").
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->foreignId('consumer_id')->nullable()->change();
            $table->foreignId('account_id')->nullable()->change();
        });

        // 2. `direction` + `event_id` toevoegen. Default `outbound` retro-vult
        //    bestaande 5b-rijen; nieuwe inbound-rijen zetten 'inbound' expliciet.
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->string('direction', 10)->default('outbound')->after('id');
            $table->string('event_id')->nullable()->after('request_fingerprint');
            $table->index(['direction', 'created_at']);
        });

        // 3. Unique constraint voor idempotency. Postgres staat meerdere NULLs
        //    toe in een unique index (default), dus outbound-rijen
        //    (event_id=NULL) blokkeren elkaar niet. SQLite kent dezelfde
        //    semantiek per SQL-standaard.
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->unique(['provider', 'event_id'], 'pass_through_calls_provider_event_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->dropUnique('pass_through_calls_provider_event_unique');
            $table->dropIndex(['direction', 'created_at']);
            $table->dropColumn(['direction', 'event_id']);
        });
        // consumer_id/account_id revert naar non-null laten we expliciet weg:
        // forward-only-prod-policy (CLAUDE.md — Migrations zijn forward-only in prod).
    }
};
