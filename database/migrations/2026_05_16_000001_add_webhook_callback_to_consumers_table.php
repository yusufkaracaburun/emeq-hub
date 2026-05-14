<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumers', function (Blueprint $table): void {
            $table->string('webhook_callback_url')->nullable()->after('slug');
            $table->text('webhook_callback_secret')->nullable()->after('webhook_callback_url');
        });
    }

    public function down(): void
    {
        Schema::table('consumers', function (Blueprint $table): void {
            $table->dropColumn(['webhook_callback_url', 'webhook_callback_secret']);
        });
    }
};
