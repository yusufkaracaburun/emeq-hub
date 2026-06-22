<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Relaties van de Books-module: books_clients (debiteuren) + books_vendors
 * (crediteuren). Lean NL-opzet — inline contact/adres i.p.v. polymorfe
 * Contact/Address, BTW-/KvK-nummer, geen Blamable (consistent met de overige
 * books_-tabellen), EUR-only.
 *
 * Een books_client mag optioneel naar een Hub-`Consumer` wijzen (de betalende
 * klant die emeq al kent): reuse zonder de bounded-context-grens te breken. De
 * link is nullOnDelete — een financieel record overleeft het wissen van de
 * operationele Consumer (NL-retentie). Crediteuren bestaan niet in de Hub.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books_clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->foreignId('consumer_id')->nullable()->constrained('consumers')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('coc_number')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country_code', 2)->default('NL');
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('books_vendors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('coc_number')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country_code', 2)->default('NL');
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books_vendors');
        Schema::dropIfExists('books_clients');
    }
};
