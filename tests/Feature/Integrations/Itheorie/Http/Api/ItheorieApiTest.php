<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Itheorie\Http\Api;

use App\Models\Consumer;
use App\Models\ProviderEntityLink;
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

    public function test_een_herhaling_na_de_idempotency_retentie_koopt_nog_steeds_niets(): void
    {
        $mock = MockClient::global([
            MockResponse::make(['token' => 'jwt-1']),
            MockResponse::make(['id' => 'p-1', 'accessCode' => 'ABC1234']),
            MockResponse::make(['id' => 'p-1', 'accessCode' => 'ABC1234']),
        ]);

        [, $token] = $this->consumerWithToken([TokenAbilities::ITHEORIE_WRITE]);
        $payload = ['course' => 'c-1', 'name' => 'Jan', 'email' => 'jan@example.com'];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Idempotency-Key', 'order-99')
            ->postJson('/v1/itheorie/purchases', $payload)
            ->assertOk();

        // De idempotency-claim is geprund; alleen het duurzame register rest.
        DB::table('idempotency_keys')->delete();

        $second = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Idempotency-Key', 'order-99')
            ->postJson('/v1/itheorie/purchases', $payload);

        $second->assertOk()->assertJsonPath('access_code', 'ABC1234');

        // Twee auth + aankoop + ophalen: geen tweede POST naar de partner.
        $mock->assertSentCount(3);
        $this->assertSame(1, ProviderEntityLink::where('provider', 'itheorie')->count());
    }

    public function test_een_afgebroken_poging_wordt_niet_stilletjes_herhaald(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::ITHEORIE_WRITE]);

        ProviderEntityLink::create([
            'consumer_id' => $consumer->getKey(),
            'provider' => 'itheorie',
            'entity_type' => ProviderEntityLink::ENTITY_PURCHASE,
            'external_id' => 'order-stuk',
            'origin' => ProviderEntityLink::ORIGIN_HUB,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Idempotency-Key', 'order-stuk')
            ->postJson('/v1/itheorie/purchases', ['course' => 'c-1', 'name' => 'Jan', 'email' => 'jan@example.com'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'purchase_in_flight');
    }

    public function test_een_afwijzing_van_de_partner_geeft_de_sleutel_weer_vrij(): void
    {
        MockClient::global([
            MockResponse::make(['token' => 'jwt-1']),
            MockResponse::make([
                'status' => 400,
                'code' => 400003,
                'message' => 'Incorrect data entered',
                'violations' => [['code' => 'not_blank', 'message' => 'Leeg', 'propertyPath' => 'email']],
            ], 400),
        ]);

        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::ITHEORIE_WRITE]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Idempotency-Key', 'order-fout')
            ->postJson('/v1/itheorie/purchases', ['course' => 'c-1', 'name' => 'Jan', 'email' => 'jan@example.com'])
            ->assertStatus(422);

        $this->assertSame(0, ProviderEntityLink::where('consumer_id', $consumer->getKey())->count());
    }

    public function test_een_consumer_ziet_de_aankoop_van_een_andere_consumer_niet(): void
    {
        $other = Consumer::factory()->create();
        ProviderEntityLink::create([
            'consumer_id' => $other->getKey(),
            'provider' => 'itheorie',
            'entity_type' => ProviderEntityLink::ENTITY_PURCHASE,
            'external_id' => 'order-van-een-ander',
            'provider_entity_id' => 'p-geheim',
            'payload_fingerprint' => hash('sha256', 'GEHEIM123'),
            'origin' => ProviderEntityLink::ORIGIN_HUB,
        ]);

        [, $token] = $this->consumerWithToken([TokenAbilities::ITHEORIE_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/itheorie/purchases/p-geheim')
            ->assertNotFound();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/itheorie/students/GEHEIM123')
            ->assertNotFound();
    }

    public function test_de_lijst_van_alle_aankopen_bestaat_niet(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::ITHEORIE_READ]);

        // 405 en niet 404: het pad bestaat nog voor de POST, alleen de lijst is weg.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/itheorie/purchases')
            ->assertStatus(405);
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
