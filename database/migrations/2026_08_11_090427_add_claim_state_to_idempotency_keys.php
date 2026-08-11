<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maakt van `idempotency_keys` een claim-tabel in plaats van een resultaat-cache.
 *
 * De middleware deed SELECT-dan-INSERT zonder lock: twee gelijktijdige requests met
 * dezelfde key misten allebei de lookup, boekten allebei bij de partner, en de
 * tweede `create()` knalde op de unique index. Voortaan is die index de mutex — de
 * rij wordt vóór de handler geclaimd. Dat vereist een staat en een lease.
 *
 * Additief op een tabel met productiedata. De default `completed` is correct voor
 * elke bestaande rij: die zijn per definitie afgerond, want er werd pas geschreven
 * ná een 2xx.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->string('state', 12)->default('completed')->after('path');
            // sha256 over METHOD + pad + rauwe body. Nullable, want rijen van vóór
            // deze migratie hebben geen fingerprint en slaan de mismatch-check over.
            $table->char('request_fingerprint', 64)->nullable()->after('state');
            $table->timestamp('locked_at')->nullable()->after('request_fingerprint');
            $table->timestamp('completed_at')->nullable()->after('locked_at');
            $table->timestamp('expires_at')->nullable()->after('created_at');
            $table->index('expires_at');
        });

        // Een lopende claim heeft nog geen status.
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->unsignedSmallInteger('response_status')->nullable()->change();
        });

        // Bestaande rijen kenden geen verval en zouden anders eeuwig blijven staan;
        // ze zaten niet in het prune-schema.
        DB::table('idempotency_keys')
            ->whereNull('expires_at')
            ->update([
                'expires_at' => now()->addHours((int) config('hub.idempotency.retention_hours', 24)),
                'completed_at' => now(),
            ]);
    }
};
