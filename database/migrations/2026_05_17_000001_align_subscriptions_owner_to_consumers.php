<?php

declare(strict_types=1);

use App\Models\Consumer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-03: alle bestaande subscription-rijen krijgen owner_type
     * = App\Models\Consumer. Cashier-Mollie's gepubliceerde migration
     * laat owner_type leeg op default; we forceren consistentie.
     *
     * Forward-only (PROJECT.md invariant). down() is een no-op om
     * lokale dev-reset niet kapot te maken.
     */
    public function up(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        DB::table('subscriptions')
            ->where(function ($query) {
                $query->whereNull('owner_type')->orWhere('owner_type', '');
            })
            ->update(['owner_type' => Consumer::class]);
    }

    public function down(): void
    {
        // Forward-only.
    }
};
