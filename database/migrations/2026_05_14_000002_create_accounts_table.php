<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_id')->constrained('consumers')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('display_name')->nullable();
            $table->timestamps();

            $table->unique(['consumer_id', 'external_id']);
            $table->index('consumer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
