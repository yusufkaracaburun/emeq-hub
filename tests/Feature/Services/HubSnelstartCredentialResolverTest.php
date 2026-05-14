<?php

namespace Tests\Feature\Services;

use App\Models\Connection;
use App\Services\Snelstart\HubSnelstartCredentialResolver;
use Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver;
use Emeq\SnelstartApi\Data\SnelstartCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class HubSnelstartCredentialResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_returns_decrypted_snelstart_credentials(): void
    {
        $connection = Connection::factory()->forSnelstart()->create([
            'client_key' => 'CK-test-1234',
            'subscription_key' => 'SK-test-5678',
            'subscription_id' => 'subscription-uuid-aaa',
        ]);

        $resolver = new HubSnelstartCredentialResolver($connection);
        $creds = $resolver->resolve();

        $this->assertInstanceOf(SnelstartCredentials::class, $creds);
        $this->assertSame('CK-test-1234', $creds->clientKey);
        $this->assertSame('SK-test-5678', $creds->subscriptionKey);
        $this->assertSame('subscription-uuid-aaa', $creds->subscriptionId);
    }

    public function test_resolver_implements_sdk_contract(): void
    {
        $resolver = new HubSnelstartCredentialResolver(
            Connection::factory()->forSnelstart()->create()
        );

        $this->assertInstanceOf(SnelstartCredentialResolver::class, $resolver);
    }

    public function test_two_resolves_on_same_connection_produce_same_fingerprint(): void
    {
        $connection = Connection::factory()->forSnelstart()->create();
        $resolver = new HubSnelstartCredentialResolver($connection);

        $first = $resolver->resolve()->fingerprint();
        $second = $resolver->resolve()->fingerprint();

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first), 'SnelstartCredentials::fingerprint() returns full sha256');
    }

    public function test_resolve_throws_when_connection_has_no_snelstart_credentials(): void
    {
        $mollieConnection = Connection::factory()->forMollie()->create();

        $resolver = new HubSnelstartCredentialResolver($mollieConnection);

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve();
    }
}
