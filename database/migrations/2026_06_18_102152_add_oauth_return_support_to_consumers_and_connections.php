<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Return-to-consumer na OAuth-connect: de geregistreerde consumer-app-base
 * (allowlist-anker + default) op Consumer, en de gevalideerde per-request
 * terugkeer-URL op Connection (gezet bij init, gelezen door de landing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumers', function (Blueprint $table) {
            $table->string('app_url')->nullable();
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->string('oauth_return_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('consumers', function (Blueprint $table) {
            $table->dropColumn('app_url');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('oauth_return_url');
        });
    }
};
