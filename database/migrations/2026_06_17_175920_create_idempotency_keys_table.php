<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hub-brede idempotentie-store voor write-requests. Consumer-scoped (de Consumer
     * bezit de key-namespace). Raw body + content-type zodat het generiek werkt voor
     * elke write-route (accounting nu, pass-through later), niet alleen JSON.
     *
     * Dit is een claim-tabel, geen resultaat-cache: de unique index op
     * (consumer_id, key) is de mutex. De rij wordt geclaimd vóór de handler draait,
     * anders missen twee gelijktijdige requests met dezelfde key allebei de lookup
     * en boeken ze allebei bij de partner. Vandaar `state`, `locked_at` en de lease.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('method', 10);
            $table->string('path');
            $table->string('state', 12)->default('completed');
            // sha256 over METHOD + pad + rauwe body — vangt hergebruik van dezelfde
            // key voor een ánder request af.
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Nullable: een lopende claim heeft nog geen status.
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('content_type')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->unique(['consumer_id', 'key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
