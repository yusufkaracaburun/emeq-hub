<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->string('query_keys')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->dropColumn('query_keys');
        });
    }
};
