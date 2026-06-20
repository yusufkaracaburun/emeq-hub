<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Verkoopfacturen van de Books-module: books_invoices (header + totalen) +
 * books_invoice_lines (regels). Lean NL-port van ERPSAAS' invoices —
 * géén logo/header/footer/discount/offerings/recurring/estimate. Bedragen in
 * integer-centen; per-regel + factuur-totalen worden door de InvoiceLineObserver
 * herrekend (subtotaal/BTW/totaal). Posten naar het grootboek volgt in een eigen
 * slice. tax_rate is een BTW-percentage (21/9/0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('books_clients')->nullOnDelete();
            $table->string('invoice_number')->nullable();
            $table->string('status')->default('draft');
            $table->date('date')->nullable();
            $table->date('due_date')->nullable();
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('books_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('books_invoices')->cascadeOnDelete();
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
        Schema::dropIfExists('books_invoice_lines');
        Schema::dropIfExists('books_invoices');
    }
};
