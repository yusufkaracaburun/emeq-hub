<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `response_body` wordt alleen bij een fout bewaard, dus een geslaagde boeking laat geen
 * spoor na van wat de Hub in de administratie deed. Deze kolom draagt uitsluitend de
 * warning-codes en hun context — niet het volledige antwoord.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->jsonb('warnings')->nullable()->after('response_body');
        });
    }
};
