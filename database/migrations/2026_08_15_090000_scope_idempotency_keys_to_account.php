<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'idempotency_keys_consumer_id_key_account_id_unique';

    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
        });

        DB::table('idempotency_keys')->where('expires_at', '<', now())->delete();

        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropUnique('idempotency_keys_consumer_id_key_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX.'
             ON idempotency_keys (consumer_id, "key", COALESCE(account_id, 0))'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        DB::table('idempotency_keys')->delete();

        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->unique(['consumer_id', 'key']);
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
