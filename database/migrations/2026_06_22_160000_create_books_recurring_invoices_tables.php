<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books_recurring_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('books_clients')->nullOnDelete();
            $table->string('status')->default('active');
            $table->string('frequency')->default('monthly');
            $table->date('start_date');
            $table->date('next_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->unsignedInteger('occurrences_count')->default(0);
            $table->unsignedSmallInteger('due_days')->default(14);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('books_recurring_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->foreignId('recurring_invoice_id')->constrained('books_recurring_invoices')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->bigInteger('unit_price')->default(0);
            $table->unsignedSmallInteger('tax_rate')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books_recurring_invoice_lines');
        Schema::dropIfExists('books_recurring_invoices');
    }
};
