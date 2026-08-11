<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correlatie-id op beide audit-tabellen, zodat één consumer-request van
 * pass-through tot inkomende webhook terug te vinden is met één query.
 *
 * Additief en nullable: beide tabellen dragen productiedata op hub.emeq.nl.
 * Bestaande rijen houden NULL — die dateren van vóór de correlatie-laag.
 *
 * Breedte 64 dekt een ULID (26), een UUID (36) en de langst geaccepteerde
 * inbound waarde (64, zie App\Http\Middleware\AssignRequestId).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->string('request_id', 64)->nullable()->after('event_id');
            $table->index('request_id');
        });

        Schema::table('inbound_webhook_events', function (Blueprint $table): void {
            $table->string('request_id', 64)->nullable()->after('request_fingerprint');
            $table->index('request_id');
        });
    }
};
