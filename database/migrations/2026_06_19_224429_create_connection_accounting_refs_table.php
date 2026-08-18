<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_accounting_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('code');
            $table->string('native_id');
            $table->string('label')->nullable();
            $table->json('attrs')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'kind', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_accounting_refs');
    }
};
