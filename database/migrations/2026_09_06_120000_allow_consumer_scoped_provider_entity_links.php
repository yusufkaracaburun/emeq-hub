<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_entity_links', function (Blueprint $table): void {
            $table->foreignId('consumer_id')->nullable()->after('connection_id')->constrained()->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE provider_entity_links ALTER COLUMN connection_id DROP NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE provider_entity_links
            ADD CONSTRAINT provider_entity_links_owner_check
            CHECK (connection_id IS NOT NULL OR consumer_id IS NOT NULL)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX provider_entity_links_consumer_canonical_unique
            ON provider_entity_links (consumer_id, entity_type, entity_subtype, external_id)
            WHERE connection_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX provider_entity_links_consumer_provider_unique
            ON provider_entity_links (consumer_id, entity_type, provider_entity_id)
            WHERE connection_id IS NULL AND provider_entity_id IS NOT NULL
        SQL);
    }
};
