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
            $table->string('public_id', 40)->unique();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('provider');
            $table->string('status')->default('active');

            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('oauth_state', 64)->nullable();
            $table->timestamp('oauth_state_expires_at')->nullable();
            $table->string('oauth_return_url')->nullable();

            $table->text('client_key')->nullable();
            $table->text('subscription_key')->nullable();
            $table->string('subscription_id')->nullable();
            $table->string('administratie_id')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'provider']);
            $table->index('oauth_state');
            $table->index(['status', 'oauth_state_expires_at']);
            $table->index(['provider', 'administratie_id']);
        });

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
