<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duurzame koppeling tussen een canonieke entity en zijn tegenhanger bij de partner.
 *
 * Vóór deze tabel overleefde de Exact-GUID van een geslaagde boeking alleen in
 * `idempotency_keys.response_body` — gesleuteld op de idempotency-key, niet op het
 * document. Een retry nadat die key weg was, boekte opnieuw.
 *
 * Provider-neutraal van opzet: geen `exact_`-kolom. Dit is tegelijk het fundament
 * voor de sync-state (welke versie, wie muteerde het laatst, is deze update stale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_entity_links', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            // Gedenormaliseerd naast connection_id, net als op pass_through_calls:
            // scheelt een join op elk diagnose-pad.
            $table->string('provider', 32);
            // 'financial_document' vandaag; 'relation', 'payment' zodra die entities
            // een eigen identiteit krijgen.
            $table->string('entity_type', 32);
            // De identiteit die de consumer aanlevert. Max 255 conform StoreDocumentRequest.
            $table->string('external_id');

            $table->string('provider_entity_id', 191)->nullable();
            $table->string('provider_entity_number', 64)->nullable();
            $table->char('payload_fingerprint', 64)->nullable();
            // hub = de Hub schreef dit naar de partner; provider = de Hub ontdekte het
            // aan partnerzijde. Dragend voor loop-detectie zodra events canoniek worden.
            $table->string('origin', 16)->default('hub');

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // De dedupe-sleutel: één canoniek document per connectie, precies één keer.
            $table->unique(['connection_id', 'entity_type', 'external_id'], 'provider_entity_links_canonical_unique');
            // Andersom ook 1:1 — vangt de bug waarbij twee canonieke documenten
            // dezelfde partner-entity claimen. Meerdere NULLs zijn toegestaan in een
            // unique index (PG en SQLite), en provider_entity_id mag legitiem NULL zijn
            // wanneer de partner geen id teruggaf.
            $table->unique(['connection_id', 'entity_type', 'provider_entity_id'], 'provider_entity_links_provider_unique');

            // Inbound richting: een partner-webhook draagt alleen het partner-id.
            $table->index('provider_entity_id');
            $table->index(['connection_id', 'last_synced_at']);
        });
    }
};
