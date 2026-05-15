<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table): void {
            // nullable: bestaande Mollie-Connections hebben geen administratie_id.
            // niet encrypted: tenant-UUID per Snelstart-OData-conventie is geen
            // secret (analoog aan subscription_id, zie 03-01 decision).
            $table->string('administratie_id')->nullable()->after('subscription_id');
            $table->index(['provider', 'administratie_id']);
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table): void {
            $table->dropIndex(['provider', 'administratie_id']);
            $table->dropColumn('administratie_id');
        });
    }
};
