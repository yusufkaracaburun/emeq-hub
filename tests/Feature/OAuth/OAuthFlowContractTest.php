<?php

namespace Tests\Feature\OAuth;

use App\Models\Connection;
use App\OAuth\Contracts\OAuthFlow;
use App\OAuth\Testing\FakeOAuthFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthFlowContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_oauth_flow_satisfies_contract(): void
    {
        $flow = new FakeOAuthFlow;

        $this->assertInstanceOf(OAuthFlow::class, $flow);
    }

    public function test_fake_oauth_flow_exchange_code_marks_connection_active(): void
    {
        $flow = new FakeOAuthFlow;
        $connection = Connection::factory()->forMollie()->pending()->create();

        $flow->exchangeCode($connection, 'fake_code');

        $connection->refresh();
        $this->assertSame('active', $connection->status);
        $this->assertStringStartsWith('access_test_fake_', $connection->access_token);
        $this->assertSame(1, $flow->wasCalled('exchangeCode'));
    }

    public function test_fake_oauth_flow_revoke_sets_revoked_status(): void
    {
        $flow = new FakeOAuthFlow;
        $connection = Connection::factory()->forMollie()->create();

        $flow->revoke($connection);

        $connection->refresh();
        $this->assertSame('revoked', $connection->status);
        $this->assertNotNull($connection->revoked_at);
    }
}
