<?php

namespace Tests\Feature;

use App\Models\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConnectionEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_snelstart_client_key_is_encrypted_at_rest(): void
    {
        $connection = Connection::factory()
            ->forSnelstart()
            ->create(['client_key' => 'CK-secret-123']);

        $rawAtRest = DB::table('connections')
            ->where('id', $connection->id)
            ->value('client_key');

        $this->assertNotSame('CK-secret-123', $rawAtRest);
        $this->assertNotEmpty($rawAtRest);
        $this->assertSame('CK-secret-123', $connection->fresh()->client_key);
    }

    public function test_snelstart_subscription_key_is_encrypted_at_rest(): void
    {
        $connection = Connection::factory()
            ->forSnelstart()
            ->create(['subscription_key' => 'SK-secret-456']);

        $rawAtRest = DB::table('connections')
            ->where('id', $connection->id)
            ->value('subscription_key');

        $this->assertNotSame('SK-secret-456', $rawAtRest);
        $this->assertSame('SK-secret-456', $connection->fresh()->subscription_key);
    }

    public function test_mollie_access_token_is_encrypted_at_rest(): void
    {
        $connection = Connection::factory()
            ->forMollie()
            ->create(['access_token' => 'access_secret-789']);

        $rawAtRest = DB::table('connections')
            ->where('id', $connection->id)
            ->value('access_token');

        $this->assertNotSame('access_secret-789', $rawAtRest);
        $this->assertSame('access_secret-789', $connection->fresh()->access_token);
    }

    public function test_mollie_refresh_token_is_encrypted_at_rest(): void
    {
        $connection = Connection::factory()
            ->forMollie()
            ->create(['refresh_token' => 'refresh_secret-789']);

        $rawAtRest = DB::table('connections')
            ->where('id', $connection->id)
            ->value('refresh_token');

        $this->assertNotSame('refresh_secret-789', $rawAtRest);
        $this->assertSame('refresh_secret-789', $connection->fresh()->refresh_token);
    }

    public function test_to_array_hides_all_credential_fields(): void
    {
        $snelstart = Connection::factory()->forSnelstart()->create();
        $mollie = Connection::factory()->forMollie()->create();

        foreach ([$snelstart, $mollie] as $connection) {
            $array = $connection->toArray();

            $this->assertArrayNotHasKey('access_token', $array);
            $this->assertArrayNotHasKey('refresh_token', $array);
            $this->assertArrayNotHasKey('client_key', $array);
            $this->assertArrayNotHasKey('subscription_key', $array);
        }
    }

    public function test_fingerprint_returns_truncated_sha256_for_snelstart(): void
    {
        $connection = Connection::factory()
            ->forSnelstart()
            ->create(['client_key' => 'CK-secret-123']);

        $expected = substr(hash('sha256', 'CK-secret-123'), 0, 12);

        $this->assertSame($expected, $connection->fingerprint());
        $this->assertSame(12, strlen($connection->fingerprint()));
    }

    public function test_fingerprint_returns_truncated_sha256_for_mollie(): void
    {
        $connection = Connection::factory()
            ->forMollie()
            ->create(['access_token' => 'access_secret-789']);

        $expected = substr(hash('sha256', 'access_secret-789'), 0, 12);

        $this->assertSame($expected, $connection->fingerprint());
    }

    public function test_fingerprint_returns_null_for_unknown_provider(): void
    {
        $connection = Connection::factory()->create([
            'provider' => 'future-provider',
            'client_key' => null,
            'access_token' => null,
        ]);

        $this->assertNull($connection->fingerprint());
    }
}
