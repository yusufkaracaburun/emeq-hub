<?php

namespace Tests\Feature\Integrations\Exact;

use App\Integrations\Exact\ConnectionTokenStore;
use App\Models\Connection;
use DateTimeImmutable;
use Emeq\ExactApi\Data\AccessToken;
use Emeq\ExactApi\Data\ExactCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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

    public function test_get_reads_the_rotation_written_by_a_parallel_process(): void
    {
        $connection = Connection::factory()->forExact()->create([
            'access_token' => 'acc_old',
            'refresh_token' => 'ref_old',
            'expires_at' => now()->subMinute(),
        ]);

        $winner = new ConnectionTokenStore(Connection::findOrFail($connection->id));
        $loser = new ConnectionTokenStore(Connection::findOrFail($connection->id));

        $winner->put($this->credentials($connection), new AccessToken(
            accessToken: 'acc_new',
            refreshToken: 'ref_new',
            expiresAt: new DateTimeImmutable('+600 seconds'),
        ));

        $token = $loser->get($this->credentials($connection));

        $this->assertSame('ref_new', $token->refreshToken);
        $this->assertSame('acc_new', $token->accessToken);
        $this->assertFalse($token->isExpired());
    }

    public function test_get_logs_a_start_marker_when_the_returned_token_is_already_expired(): void
    {
        $connection = Connection::factory()->forExact()->create([
            'access_token' => 'acc',
            'refresh_token' => 'ref',
            'expires_at' => now()->subMinute(),
        ]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($connection): bool {
                $serialized = $message.json_encode($context);

                return $message === 'exact.oauth.refresh_attempt_started'
                    && $context['connection_id'] === $connection->id
                    && $context['refresh_token_fingerprint'] === substr(hash('sha256', 'ref'), 0, 12)
                    && ! str_contains($serialized, 'acc')
                    && ! str_contains($serialized, '"ref"');
            });

        (new ConnectionTokenStore($connection))->get($this->credentials($connection));
    }

    public function test_get_does_not_log_a_start_marker_when_the_token_is_still_valid(): void
    {
        $connection = Connection::factory()->forExact()->create([
            'access_token' => 'acc',
            'refresh_token' => 'ref',
            'expires_at' => now()->addSeconds(600),
        ]);

        Log::shouldReceive('info')->never();

        (new ConnectionTokenStore($connection))->get($this->credentials($connection));
    }

    public function test_get_returns_null_when_the_connection_row_is_gone(): void
    {
        $connection = Connection::factory()->forExact()->create([
            'access_token' => 'acc',
            'refresh_token' => 'ref',
            'expires_at' => now()->addSeconds(600),
        ]);

        $store = new ConnectionTokenStore($connection);
        Connection::whereKey($connection->id)->delete();

        $this->assertNull($store->get($this->credentials($connection)));
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

    public function test_put_logs_the_rotation_with_fingerprints_never_the_raw_tokens(): void
    {
        $connection = Connection::factory()->forExact()->create([
            'access_token' => 'acc_old',
            'refresh_token' => 'ref_old',
        ]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($connection): bool {
                $serialized = $message.json_encode($context);

                return $message === 'exact.oauth.refresh_token_rotated'
                    && $context['connection_id'] === $connection->id
                    && $context['old_refresh_token_fingerprint'] === substr(hash('sha256', 'ref_old'), 0, 12)
                    && $context['new_refresh_token_fingerprint'] === substr(hash('sha256', 'ref_new'), 0, 12)
                    && ! str_contains($serialized, 'ref_old')
                    && ! str_contains($serialized, 'ref_new')
                    && ! str_contains($serialized, 'acc_old')
                    && ! str_contains($serialized, 'acc_new');
            });

        (new ConnectionTokenStore($connection))->put($this->credentials($connection), new AccessToken(
            accessToken: 'acc_new',
            refreshToken: 'ref_new',
            expiresAt: new DateTimeImmutable('+600 seconds'),
        ));
    }
}
