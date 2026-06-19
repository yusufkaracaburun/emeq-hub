<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('connection_accounting_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            // gl | vat | journal | relation — gemirrorde boekhoud-referentiedata.
            $table->string('kind');
            // Stabiele, leesbare sleutel: GL-/dagboek-/BTW-Code, of party.external_id voor relaties.
            $table->string('code');
            // Provider-native identiteit (Exact GUID) — waar de boeking naar resolvet.
            $table->string('native_id');
            $table->string('label')->nullable();
            // kind-specifiek: {percentage} voor vat, {type} voor journal, etc.
            $table->json('attrs')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'kind', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connection_accounting_refs');
    }
};
