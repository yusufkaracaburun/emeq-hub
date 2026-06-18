<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('provider');
            $table->string('status')->default('active');

            // OAuth-shape (Mollie, future Exact/Ibanity)
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('oauth_state', 64)->nullable();
            $table->timestamp('oauth_state_expires_at')->nullable();
            // Per-request gevalideerde terugkeer-URL (gezet bij init, gelezen door de landing).
            $table->string('oauth_return_url')->nullable();

            // Key-based-shape (Snelstart) — subscription_id + administratie_id zijn geen secrets (tenant-UUIDs)
            $table->text('client_key')->nullable();
            $table->text('subscription_key')->nullable();
            $table->string('subscription_id')->nullable();
            $table->string('administratie_id')->nullable();

            // Provider-specifieke overflow
            $table->json('metadata')->nullable();

            // Audit
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'provider']);
            $table->index('oauth_state');
            $table->index(['status', 'oauth_state_expires_at']);
            $table->index(['provider', 'administratie_id']);
        });

        // Partial unique: één actieve Connection per (account, provider). Revoked rijen vrij.
        DB::statement(
            'CREATE UNIQUE INDEX connections_account_id_provider_active_unique '
            .'ON connections (account_id, provider) WHERE revoked_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
