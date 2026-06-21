<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Inkoopfacturen van de Books-module: books_bills (header + totalen) +
 * books_bill_lines (regels). Spiegel van books_invoices, met twee verschillen:
 * een bill hangt aan een crediteur (vendor_id) i.p.v. debiteur, en elke regel
 * draagt een eigen kostenrekening (account_id) — inkoop is een echte categorie-
 * keuze (4000/4400/4500), niet uit het BTW-tarief af te leiden zoals omzet. Voor
 * de boeking volgt input-BTW één rekening (1530), dus tax_rate stuurt alleen het
 * bedrag, niet de rekening. Bedragen in integer-centen; per-regel + bill-totalen
 * worden door de BillLineObserver herrekend (subtotaal/BTW/totaal). tax_rate is
 * een BTW-percentage (21/9/0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books_bills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('books_vendors')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('books_transactions')->nullOnDelete();
            $table->string('bill_number')->nullable();
            $table->string('status')->default('draft');
            $table->date('date')->nullable();
            $table->date('due_date')->nullable();
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('books_bill_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained('books_bills')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('books_accounts')->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->bigInteger('unit_price')->default(0);
            $table->unsignedSmallInteger('tax_rate')->default(0);
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books_bill_lines');
        Schema::dropIfExists('books_bills');
    }
};
