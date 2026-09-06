<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Itheorie\Http\Api;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\ItheorieApi\Contracts\ItheorieCredentialResolver;
use Emeq\ItheorieApi\Data\ItheorieCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

final class ItheorieApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MockClient::destroyGlobal();

        $this->app->bind(ItheorieCredentialResolver::class, fn (): ItheorieCredentialResolver => new class implements ItheorieCredentialResolver
        {
            public function resolve(): ItheorieCredentials
            {
                return new ItheorieCredentials('lens-id', 'geheim', '12345678', 'https://itheorie.test/api/connect');
            }
        });
    }

    public function test_cursussen_komen_genormaliseerd_terug(): void
    {
        MockClient::global([
            MockResponse::make(['token' => 'jwt-1']),
            MockResponse::make(['data' => [['id' => 'c-1', 'title' => 'Auto', 'offer' => ['currentPrice' => ['amount' => '9.70']]]], 'links' => []]),
        ]);

        [, $token] = $this->consumerWithToken([TokenAbilities::ITHEORIE_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/itheorie/courses')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'c-1')
            ->assertJsonPath('data.0.offer.current_price', 9.7);
    }

    public function test_een_herhaalde_idempotency_key_koopt_geen_tweede_code(): void
    {
        $mock = MockClient::global([
            MockResponse::make(['token' => 'jwt-1']),
            MockResponse::make(['id' => 'p-1', 'accessCode' => 'ABC1234']),
        ]);

        [, $token] = $this->consumerWithToken([TokenAbilities::ITHEORIE_WRITE]);

        $payload = ['course' => 'c-1', 'name' => 'Jan', 'email' => 'jan@example.com'];

        $first = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Idempotency-Key', 'order-42')
            ->postJson('/v1/itheorie/purchases', $payload);

        $second = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Idempotency-Key', 'order-42')
            ->postJson('/v1/itheorie/purchases', $payload);

        $first->assertOk()->assertJsonPath('access_code', 'ABC1234');
        $second->assertOk()->assertJsonPath('access_code', 'ABC1234');
        $second->assertHeader('Idempotent-Replayed', 'true');

        $mock->assertSentCount(2);
    }

    public function test_een_aankoop_zonder_idempotency_key_wordt_geweigerd(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::ITHEORIE_WRITE]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/itheorie/purchases', ['course' => 'c-1', 'name' => 'Jan', 'email' => 'jan@example.com'])
            ->assertStatus(400)
            ->assertJsonPath('error', 'idempotency_key_required');
    }

    public function test_een_validatiefout_van_de_partner_komt_terug_als_422_met_violations(): void
    {
        MockClient::global([
            MockResponse::make(['token' => 'jwt-1']),
            MockResponse::make([
                'status' => 400,
                'code' => 400003,
                'message' => 'Incorrect data entered',
                'violations' => [['code' => 'not_blank', 'message' => 'Mag niet leeg zijn', 'propertyPath' => 'email']],
            ], 400),
        ]);

        [, $token] = $this->consumerWithToken([TokenAbilities::ITHEORIE_WRITE]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Idempotency-Key', 'order-43')
            ->postJson('/v1/itheorie/purchases', ['course' => 'c-1', 'name' => 'Jan', 'email' => 'jan@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('upstream_detail', '400003')
            ->assertJsonPath('violations.0.propertyPath', 'email');
    }

    public function test_een_kapotte_broker_inlog_is_geen_401_voor_de_consumer(): void
    {
        MockClient::global([
            MockResponse::make(['status' => 401, 'code' => 401010, 'message' => 'Broker not found'], 401),
        ]);

        [, $token] = $this->consumerWithToken([TokenAbilities::ITHEORIE_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/itheorie/courses')
            ->assertStatus(502)
            ->assertJsonPath('error', 'upstream_auth_failed');
    }

    public function test_lezen_mag_niet_kopen(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::ITHEORIE_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Idempotency-Key', 'order-44')
            ->postJson('/v1/itheorie/purchases', ['course' => 'c-1', 'name' => 'Jan', 'email' => 'jan@example.com'])
            ->assertForbidden();
    }

    public function test_de_audit_rij_draagt_geen_connection_en_geen_geheimen(): void
    {
        MockClient::global([
            MockResponse::make(['token' => 'jwt-1']),
            MockResponse::make(['data' => [], 'links' => []]),
        ]);

        [, $token] = $this->consumerWithToken([TokenAbilities::ITHEORIE_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/itheorie/courses')
            ->assertOk();

        $row = DB::table('pass_through_calls')->where('provider', 'itheorie')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertNull($row->connection_id);
        $this->assertNull($row->account_id);
        $this->assertSame('/itheorie/courses', $row->path);
        $this->assertStringNotContainsString('geheim', json_encode($row, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('jwt-1', json_encode($row, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<string>  $abilities
     * @return array{0: Consumer, 1: string}
     */
    private function consumerWithToken(array $abilities): array
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('test', $abilities)->plainTextToken;

        return [$consumer, $token];
    }
}
