<?php

namespace Tests\Feature;

use App\Models\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConnectionAdministratieIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_administratie_id_persists_unencrypted(): void
    {
        $connection = Connection::factory()
            ->forSnelstart()
            ->create(['administratie_id' => 'abc-123']);

        $rawAtRest = DB::table('connections')
            ->where('id', $connection->id)
            ->value('administratie_id');

        $this->assertSame('abc-123', $rawAtRest);
        $this->assertSame('abc-123', $connection->fresh()->administratie_id);
    }

    public function test_factory_for_snelstart_sets_administratie_id(): void
    {
        $connection = Connection::factory()->forSnelstart()->create();

        $this->assertNotEmpty($connection->administratie_id);
        $this->assertTrue(Str::isUuid($connection->administratie_id));
    }

    public function test_lookup_by_provider_and_administratie_id_returns_connection(): void
    {
        Connection::factory()->forMollie()->create();
        $expectedUuid = (string) Str::uuid();
        $otherUuid = (string) Str::uuid();

        $expected = Connection::factory()
            ->forSnelstart()
            ->create(['administratie_id' => $expectedUuid]);
        Connection::factory()
            ->forSnelstart()
            ->create(['administratie_id' => $otherUuid]);

        $found = Connection::query()
            ->where('provider', 'snelstart')
            ->where('administratie_id', $expectedUuid)
            ->first();

        $this->assertNotNull($found);
        $this->assertSame($expected->id, $found->id);
    }
}
