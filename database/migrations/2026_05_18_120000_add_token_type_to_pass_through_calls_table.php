<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->string('token_type', 16)->nullable()->after('provider')->index();
            $table->string('partner_token_fingerprint', 16)->nullable()->after('request_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->dropIndex(['token_type']);
            $table->dropColumn(['token_type', 'partner_token_fingerprint']);
        });
    }
};
