<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Betalingen/afletteringen van de Books-module. Eén rij = één allocatie: ze
 * koppelt een bank-leg (transaction_id, een Deposit/Withdrawal die LedgerPoster
 * al boekt op 1100 ↔ 1300/1600) aan een open post (payable = factuur of bill)
 * met een bedrag in centen. Polymorf + per-allocatie-bedrag → ondersteunt zowel
 * deelbetalingen (meerdere rijen per doc) als bank-driven afletteren (één
 * banktransactie over meerdere docs). "Betaald" wordt afgeleid uit Σ allocaties
 * vs doc-totaal — geen status-kolom op de doc-tabellen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('books_transactions')->cascadeOnDelete();
            $table->morphs('payable');
            $table->bigInteger('amount')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books_payments');
    }
};
