<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connection_accounting_refs', function (Blueprint $table) {
            $table->index(['connection_id', 'kind', 'native_id']);
        });
    }
};
