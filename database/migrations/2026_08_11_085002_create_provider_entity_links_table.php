<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_entity_links', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('entity_type', 32);
            $table->string('entity_subtype', 32)->default('');
            $table->string('external_id');

            $table->string('provider_entity_id', 191)->nullable();
            $table->string('provider_entity_number', 64)->nullable();
            $table->char('payload_fingerprint', 64)->nullable();
            $table->string('origin', 16)->default('hub');

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'entity_type', 'entity_subtype', 'external_id'], 'provider_entity_links_canonical_unique');
            $table->unique(['connection_id', 'entity_type', 'provider_entity_id'], 'provider_entity_links_provider_unique');

            $table->index('provider_entity_id');
            $table->index(['connection_id', 'last_synced_at']);
        });
    }
};
