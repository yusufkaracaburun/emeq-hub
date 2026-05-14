<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->string('oauth_state', 64)->nullable()->after('scopes');
            $table->timestamp('oauth_state_expires_at')->nullable()->after('oauth_state');

            $table->index('oauth_state');
            $table->index(['status', 'oauth_state_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropIndex(['status', 'oauth_state_expires_at']);
            $table->dropIndex(['oauth_state']);
            $table->dropColumn(['oauth_state', 'oauth_state_expires_at']);
        });
    }
};
