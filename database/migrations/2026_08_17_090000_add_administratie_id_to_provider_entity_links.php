<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_entity_links', function (Blueprint $table): void {
            $table->string('administratie_id')->default('')->after('provider');

            $table->index(
                ['provider', 'administratie_id', 'entity_type', 'entity_subtype', 'external_id'],
                'provider_entity_links_administration_index',
            );
        });

        DB::table('connections')
            ->select(['id', 'administratie_id'])
            ->orderBy('id')
            ->chunk(500, function ($connections): void {
                foreach ($connections as $connection) {
                    DB::table('provider_entity_links')
                        ->where('connection_id', $connection->id)
                        ->update(['administratie_id' => (string) ($connection->administratie_id ?? '')]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('provider_entity_links', function (Blueprint $table): void {
            $table->dropIndex('provider_entity_links_administration_index');
            $table->dropColumn('administratie_id');
        });
    }
};
