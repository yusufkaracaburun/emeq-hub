<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Books-module kern-domein (Fase 2). Geport uit ERPSAAS, met `books_`-prefix
 * (D2 — ERPSAAS' `accounts` botst met Hub's bestaande `accounts`-tabel) en een
 * vaste single-company FK naar books_companies (D1). Plaid/institutions +
 * created_by/updated_by-audit + currencies-tabel zijn bewust weggelaten in v1.
 * Zie .docs/decisions/erpsaas-books-module.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books_companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('books_account_subtypes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->boolean('multi_currency')->default(false);
            $table->string('category');
            $table->string('type');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('books_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->foreignId('subtype_id')->nullable()->constrained('books_account_subtypes')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('books_accounts')->nullOnDelete();
            $table->string('category')->nullable();
            $table->string('type')->nullable();
            $table->string('code')->nullable()->index();
            $table->string('name')->nullable()->index();
            $table->string('currency_code')->nullable();
            $table->text('description')->nullable();
            $table->boolean('archived')->default(false);
            $table->boolean('default')->default(false);
            $table->timestamps();
        });

        Schema::create('books_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('books_accounts')->nullOnDelete();
            $table->string('type')->default('depository');
            $table->string('number', 34)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('books_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('books_accounts')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('books_bank_accounts')->nullOnDelete();
            $table->string('type'); // deposit, withdrawal, journal, transfer
            $table->string('payment_channel')->nullable();
            $table->string('payment_method')->nullable();
            $table->boolean('is_payment')->default(false);
            $table->string('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('reference')->nullable();
            $table->bigInteger('amount')->default(0);
            $table->json('meta')->nullable();
            $table->boolean('pending')->default(false);
            $table->boolean('reviewed')->default(false);
            $table->dateTime('posted_at');
            $table->timestamps();

            $table->index(['account_id', 'posted_at']);
            $table->index(['bank_account_id', 'posted_at']);
        });

        Schema::create('books_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('books_accounts')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('books_transactions')->cascadeOnDelete();
            $table->string('type'); // debit, credit
            $table->bigInteger('amount')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'type']);
            $table->index(['account_id', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books_journal_entries');
        Schema::dropIfExists('books_transactions');
        Schema::dropIfExists('books_bank_accounts');
        Schema::dropIfExists('books_accounts');
        Schema::dropIfExists('books_account_subtypes');
        Schema::dropIfExists('books_companies');
    }
};
