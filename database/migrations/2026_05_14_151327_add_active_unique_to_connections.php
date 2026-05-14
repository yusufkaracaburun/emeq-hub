<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX connections_account_id_provider_active_unique '
            .'ON connections (account_id, provider) WHERE revoked_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS connections_account_id_provider_active_unique');
    }
};
