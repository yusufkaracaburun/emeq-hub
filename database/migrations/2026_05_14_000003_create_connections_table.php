<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('provider');
            $table->string('status')->default('active');

            // OAuth-shape (Mollie, future Exact/Ibanity)
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('scopes')->nullable();

            // Key-based-shape (Snelstart) — subscription_id is geen secret (tenant-UUID)
            $table->text('client_key')->nullable();
            $table->text('subscription_key')->nullable();
            $table->string('subscription_id')->nullable();

            // Provider-specifieke overflow
            $table->json('metadata')->nullable();

            // Audit
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
