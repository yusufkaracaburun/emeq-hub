<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Integrations\Errors\ErrorCode;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\RawExactRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

/**
 * Elke `/v1/*`-fout draagt dezelfde envelope, ongeacht welke van de ~50 plekken hem
 * produceerde — inclusief de framework-fouten die er nooit één hadden.
 */
class ErrorEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    private function consumerWithExactConnection(): Consumer
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);

        Connection::factory()->forExact()->create([
            'account_id' => $account->id,
            'status' => 'active',
            'expires_at' => now()->addSeconds(600),
        ]);

        return $consumer;
    }

    public function test_an_unauthenticated_request_carries_the_full_envelope(): void
    {
        $this->getJson('/v1/ping')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'unauthenticated')
            ->assertJsonPath('category', ErrorCode::AuthenticationError->value)
            ->assertJsonStructure(['error', 'category', 'message', 'request_id']);
    }

    /**
     * De 401 droeg historisch `code` in plaats van `error`. Die sleutel blijft staan
     * voor wie erop leest, maar `error` staat er nu ook.
     */
    public function test_the_legacy_code_key_is_preserved_next_to_error(): void
    {
        $this->getJson('/v1/ping')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated')
            ->assertJsonPath('error', 'unauthenticated');
    }

    /**
     * `abort_unless(..., 403, 'insufficient_ability')` leverde een kale `{message}`.
     * Een consumer die op `error` leest kreeg daar niets.
     */
    public function test_an_abort_based_403_now_carries_an_error_key(): void
    {
        $consumer = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', [
                'type' => 'sales_invoice',
                'external_id' => 'INV-1',
                'issue_date' => '2026-06-16',
                'party' => ['role' => 'debtor', 'name' => 'Acme BV'],
                'lines' => [['description' => 'A', 'amount' => 1, 'tax_rate' => 0]],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'insufficient_ability')
            ->assertJsonPath('category', ErrorCode::AuthorizationError->value);
    }

    /**
     * Framework-validatie had helemaal geen `error`-sleutel.
     */
    public function test_a_validation_failure_carries_the_envelope(): void
    {
        $consumer = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', ['type' => 'onzin']);

        $response->assertStatus(422)
            ->assertJsonPath('category', ErrorCode::ValidationError->value)
            ->assertJsonStructure(['error', 'category', 'request_id', 'errors']);
    }

    /**
     * De categorie volgt de betekenis van de code, niet alleen de status: dit is een
     * 422 die eigenlijk "koppeling ontbreekt" betekent.
     */
    public function test_a_mapping_failure_is_categorised_as_a_missing_reference(): void
    {
        $consumer = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        // Geen division op de Connection → AccountingMappingException → 422 mapping_failed.
        Connection::query()->update(['administratie_id' => null]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', [
                'type' => 'sales_invoice',
                'external_id' => 'INV-MAP',
                'issue_date' => '2026-06-16',
                'party' => ['role' => 'debtor', 'name' => 'Acme BV'],
                'lines' => [['description' => 'A', 'amount' => 1, 'tax_rate' => 0]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'mapping_failed')
            ->assertJsonPath('category', ErrorCode::ReferenceMappingMissing->value);
    }

    public function test_the_request_id_in_the_envelope_matches_the_response_header(): void
    {
        $response = $this->withHeader('X-Request-Id', 'envelope-test-0001')->getJson('/v1/ping');

        $response->assertUnauthorized();
        $this->assertSame('envelope-test-0001', $response->json('request_id'));
        $this->assertSame('envelope-test-0001', $response->headers->get('X-Request-Id'));
    }

    /**
     * Alleen fouten worden aangeraakt; een geslaagde respons blijft precies wat hij was.
     */
    public function test_a_successful_response_is_left_alone(): void
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('t', [TokenAbilities::ADMIN])->plainTextToken;

        $response = $this->withToken($token)->getJson('/v1/ping');

        $response->assertOk();
        $this->assertArrayNotHasKey('category', $response->json());
        $this->assertArrayNotHasKey('error', $response->json());
    }

    /**
     * De envelope wordt over foutresponses heen gelegd; dat mag nooit een pad worden
     * waarlangs het bearer-token of een partner-secret naar buiten komt.
     */
    public function test_the_envelope_never_echoes_credentials(): void
    {
        $consumer = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', [
                'type' => 'sales_invoice',
                'external_id' => 'INV-SECRET',
                'issue_date' => '2026-06-16',
                'party' => ['role' => 'debtor', 'name' => 'Acme BV'],
                'lines' => [['description' => 'A', 'amount' => 1, 'tax_rate' => 0]],
            ]);

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString('Bearer', $body);
        $this->assertStringNotContainsString('Authorization', $body);
    }

    /**
     * De pass-through-controllers geven `response($body, $status)` terug — een gewone
     * Response met een JSON-content-type, geen JsonResponse. Dat is het merendeel van
     * het foutverkeer; die overslaan zou de envelope grotendeels leeg laten lopen.
     */
    public function test_a_pass_through_error_carries_the_envelope_too(): void
    {
        config([
            'services.exact.client_id' => 'app_test_id',
            'services.exact.client_secret' => 'app_test_secret',
            'services.exact.redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
            'services.exact.api_base_url' => 'https://start.exactonline.nl',
        ]);
        MockClient::global([
            RawExactRequest::class => MockResponse::make(['error' => ['message' => ['value' => 'stuk']]], 503),
        ]);

        $consumer = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/exact/financial/GLAccounts');

        $this->assertGreaterThanOrEqual(500, $response->status());
        $this->assertNotNull($response->json('category'));
        $this->assertNotNull($response->json('request_id'));
        $this->assertNotNull($response->json('error'));

        MockClient::destroyGlobal();
    }

    /**
     * Een JSON-lijst zou door de spread in een object veranderen (`{"0":…}`). Dat is
     * geen envelope maar een vormwijziging, dus die body blijft ongemoeid.
     */
    public function test_a_list_body_is_left_untouched(): void
    {
        Route::middleware('api')->get('/v1/__test_list_error', fn () => response()->json(['a', 'b'], 422));

        $response = $this->getJson('/v1/__test_list_error');

        $response->assertStatus(422);
        $this->assertSame(['a', 'b'], $response->json());
    }

    /**
     * De envelope hoort alleen bij de consumer-API, niet bij de publieke webpagina's.
     */
    public function test_non_v1_routes_are_untouched(): void
    {
        $this->getJson('/webhooks/exact')->assertStatus(405);

        $this->assertArrayNotHasKey('category', (array) $this->getJson('/webhooks/exact')->json());
    }
}
