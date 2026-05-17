<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_calls', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Phase 9 / D-02 audit-kolommen
            $table->enum('direction', ['incoming', 'outgoing'])->default('incoming');
            $table->string('provider', 32)->nullable();
            $table->foreignId('consumer_id')->nullable()->constrained('consumers')->nullOnDelete();
            $table->enum('status', ['pending', 'processed', 'failed'])->default('processed');

            // Spatie webhook-client base shape
            $table->string('name');
            $table->string('url', 512);
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->json('attachments')->nullable();
            $table->text('exception')->nullable();

            $table->timestamps();

            $table->index('direction');
            $table->index('provider');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_calls');
    }
};
