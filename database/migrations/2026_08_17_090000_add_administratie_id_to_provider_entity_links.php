<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De échte administratie naast de Connection, zodat dubbel boeken over twee
 * koppelingen heen zichtbaar wordt.
 *
 * `provider_entity_links_canonical_unique` sluit per Connection af, maar één
 * administratie mag door meerdere Accounts gekoppeld zijn — de boekhouder via de
 * ene Consumer-app, de ondernemer via de andere. ExactWebhookController rekent
 * daar expliciet op. Aan de boekkant deelden die twee tot nu toe geen enkele
 * grendel: allebei claimen, allebei boeken, twee journaalposten in hetzelfde
 * grootboek.
 *
 * Gedenormaliseerd van `connections`, net als `provider`: de check draait op het
 * hete claim-pad en mag daar geen join kosten.
 *
 * NOT NULL met lege default, net als `entity_subtype`: in een index telt elke NULL
 * als uniek. Leeg betekent hier "deze provider levert geen administratie-id" en
 * schakelt de kruis-connectie-check bewust uit — alle lege waarden op één hoop
 * gooien zou losstaande administraties als één behandelen en echte boekingen
 * weigeren. Zie ProviderEntityLinkRecorder::findPostedOnSameAdministration().
 *
 * Bewust een gewone index en geen unique: gelijk `external_id` over twee
 * koppelingen is niet per definitie hetzelfde document — twee apps met een eigen
 * nummerreeks gebruiken allebei "2026-001". De fingerprint beslist, in code.
 */
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

        // Per Connection bijwerken in plaats van één UPDATE ... FROM: dat is
        // Postgres-eigen syntax en deze migratie draait ook op SQLite in de tests.
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
