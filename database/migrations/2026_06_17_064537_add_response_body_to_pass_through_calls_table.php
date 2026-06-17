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
        Schema::table('pass_through_calls', function (Blueprint $table) {
            // Errors-only response-body (status >= 400), capped + redacted. Geen
            // succesvolle reads → minimaliseert klant-PII at rest in de audit-tabel.
            $table->text('response_body')->nullable()->after('upstream_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pass_through_calls', function (Blueprint $table) {
            $table->dropColumn('response_body');
        });
    }
};
