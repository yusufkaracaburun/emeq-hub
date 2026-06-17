<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hub-brede idempotentie-store voor write-requests. Consumer-scoped (de Consumer
     * bezit de key-namespace); de EnsureIdempotency-middleware bewaart de eerste
     * succesvolle respons en herhaalt die bij een retry met dezelfde key. Raw body +
     * content-type zodat het generiek werkt voor elke write-route (accounting nu,
     * pass-through later), niet alleen JSON.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('method', 10);
            $table->string('path');
            $table->unsignedSmallInteger('response_status');
            $table->string('content_type')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['consumer_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
