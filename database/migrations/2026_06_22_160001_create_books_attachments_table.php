<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bijlagen (bonnetjes/scans) van de Books-module. Polymorf (attachable →
 * Invoice/Bill), mirror de books_payments-aanpak. Bestanden staan op een
 * private disk (financiële documenten, AVG) — deze rij draagt enkel metadata +
 * het pad. company-scoped + cascadeOnDelete dekt recht-op-vergeten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('books_companies')->cascadeOnDelete();
            $table->morphs('attachable');
            $table->string('original_name');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books_attachments');
    }
};
