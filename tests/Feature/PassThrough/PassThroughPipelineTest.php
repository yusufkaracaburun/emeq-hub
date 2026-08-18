<?php

declare(strict_types=1);

namespace Tests\Feature\PassThrough;

use App\Enums\Provider;
use App\Integrations\PassThrough\PassThroughContext;
use App\Integrations\PassThrough\PassThroughPipeline;
use App\Integrations\PassThrough\UpstreamResult;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\PassThroughCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class PassThroughPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Consumer $consumer;

    private Account $account;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = Consumer::factory()->create();
        $this->account = Account::factory()->for($this->consumer)->create();
        $this->connection = Connection::factory()->for($this->account)->create(['provider' => Provider::Exact->value]);
    }

    public function test_geeft_de_partner_response_ongewijzigd_door_en_logt_de_call(): void
    {
        $response = $this->pipeline()->run(
            $this->context(),
            fn () => new UpstreamResult(
                status: 200,
                body: '{"d":{"results":[]}}',
                contentType: 'application/json;charset=utf-8',
                headers: ['X-RateLimit-Remaining' => '4999'],
            ),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"d":{"results":[]}}', $response->getContent());
        $this->assertSame('application/json;charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertSame('4999', $response->headers->get('X-RateLimit-Remaining'));

        $call = PassThroughCall::sole();
        $this->assertSame(200, $call->status);
        $this->assertSame('/crm/Accounts', $call->path);
        $this->assertNull($call->upstream_error);
    }

    public function test_vertaalt_een_partner_exception_via_de_registry_van_die_provider(): void
    {
        $response = $this->pipeline()->run(
            $this->context(),
            fn () => throw new RuntimeException('kapot'),
        );

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));

        $call = PassThroughCall::sole();
        $this->assertSame(502, $call->status);
        $this->assertNotNull($call->upstream_error);
    }

    public function test_meldt_de_status_die_de_partner_zelf_gaf_ook_als_die_gemaskeerd_wordt(): void
    {
        $observed = [];

        $this->pipeline()->run(
            $this->context(),
            fn () => new UpstreamResult(status: 204, body: ''),
            function (int $status) use (&$observed) {
                $observed[] = $status;
            },
        );

        $this->assertSame([204], $observed);
    }

    public function test_schrijft_de_auditrij_ook_wanneer_de_call_faalt(): void
    {
        $this->pipeline()->run($this->context(), fn () => throw new RuntimeException('kapot'));

        $this->assertSame(1, PassThroughCall::count());
    }

    public function test_ondersteunt_een_call_zonder_account_of_connection(): void
    {
        $fingerprint = substr(hash('sha256', 'connect-test'), 0, 12);

        $this->pipeline()->run(
            new PassThroughContext(
                provider: Provider::Mollie,
                consumerId: $this->consumer->getKey(),
                accountId: null,
                connectionId: null,
                method: 'GET',
                path: '/v2/onboarding/me',
                direction: 'outbound',
                extra: ['token_type' => 'partner', 'partner_token_fingerprint' => $fingerprint],
            ),
            fn () => UpstreamResult::json(['status' => 'completed'], 200),
        );

        $call = PassThroughCall::sole();
        $this->assertNull($call->account_id);
        $this->assertNull($call->connection_id);
        $this->assertSame('partner', $call->token_type);
        $this->assertSame($fingerprint, $call->partner_token_fingerprint);
        $this->assertSame('outbound', $call->direction);
    }

    private function pipeline(): PassThroughPipeline
    {
        return $this->app->make(PassThroughPipeline::class);
    }

    private function context(): PassThroughContext
    {
        return new PassThroughContext(
            provider: Provider::Exact,
            consumerId: $this->consumer->getKey(),
            accountId: $this->account->getKey(),
            connectionId: $this->connection->getKey(),
            method: 'GET',
            path: '/crm/Accounts',
            query: ['$top' => 5],
        );
    }
}
