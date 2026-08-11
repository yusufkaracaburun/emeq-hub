<?php

namespace Tests\Feature\Exact;

use App\Integrations\Exact\ConnectionTokenStore;
use App\Models\Connection;
use DateTimeImmutable;
use Emeq\ExactApi\Data\AccessToken;
use Emeq\ExactApi\Data\ExactCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionTokenStoreTest extends TestCase
{
    use RefreshDatabase;

    private function credentials(Connection $connection): ExactCredentials
    {
        return new ExactCredentials(
            clientId: 'cid',
            clientSecret: 'sec',
            redirectUri: 'https://cb',
            connectionRef: (string) $connection->id,
        );
    }

    public function test_get_returns_null_when_connection_has_no_token(): void
    {
        $connection = Connection::factory()->forExact()->create([
            'access_token' => null,
            'refresh_token' => null,
        ]);

        $store = new ConnectionTokenStore($connection);

        $this->assertNull($store->get($this->credentials($connection)));
    }

    public function test_get_builds_access_token_from_connection(): void
    {
        $connection = Connection::factory()->forExact()->create([
            'access_token' => 'acc',
            'refresh_token' => 'ref',
            'expires_at' => now()->addSeconds(600),
        ]);

        $token = (new ConnectionTokenStore($connection))->get($this->credentials($connection));

        $this->assertInstanceOf(AccessToken::class, $token);
        $this->assertSame('acc', $token->accessToken);
        $this->assertSame('ref', $token->refreshToken);
        $this->assertFalse($token->isExpired());
    }

    public function test_put_persists_rotated_tokens_to_connection(): void
    {
        $connection = Connection::factory()->forExact()->create([
            'access_token' => 'acc_old',
            'refresh_token' => 'ref_old',
        ]);

        $store = new ConnectionTokenStore($connection);
        $store->put($this->credentials($connection), new AccessToken(
            accessToken: 'acc_new',
            refreshToken: 'ref_new',
            expiresAt: new DateTimeImmutable('+600 seconds'),
        ));

        $connection->refresh();
        $this->assertSame('acc_new', $connection->access_token);
        $this->assertSame('ref_new', $connection->refresh_token);
    }
}
