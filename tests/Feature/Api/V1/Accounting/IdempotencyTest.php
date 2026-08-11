<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Accounting;

use App\Accounting\AccountingResult;
use App\Accounting\Contracts\AccountingTarget;
use App\Accounting\Enums\DocumentType;
use App\Accounting\Enums\TaxTreatment;
use App\Accounting\Exact\Contracts\ExactReferenceResolver;
use App\Accounting\Exact\ExactAccountingTarget;
use App\Accounting\FinancialDocument;
use App\Accounting\Party;
use App\Http\Middleware\EnsureIdempotency;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\IdempotencyKey;
use App\Sanctum\TokenAbilities;
use ArrayObject;
use Emeq\ExactApi\Http\Request\Write\CreateSalesEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

/**
 * De claim-laag. Vóór deze fase deed de middleware SELECT-dan-INSERT zonder lock:
 * twee gelijktijdige requests met dezelfde key boekten allebei bij Exact en de
 * tweede `create()` gaf een 500. De unique index is nu de mutex.
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.exact.client_id' => 'app_test_id',
            'services.exact.client_secret' => 'app_test_secret',
            'services.exact.redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
            'services.exact.api_base_url' => 'https://start.exactonline.nl',
        ]);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        parent::tearDown();
    }

    private function bindFakeReferences(): void
    {
        $this->app->bind(ExactReferenceResolver::class, fn (): ExactReferenceResolver => new class implements ExactReferenceResolver
        {
            public function relationGuid(Party $party, Connection $connection): string
            {
                return 'cust-guid';
            }

            public function vatCode(float $taxRate, TaxTreatment $treatment, Connection $connection): string
            {
                return '4';
            }

            public function glAccountGuid(?string $category, Connection $connection): ?string
            {
                return 'gl-guid';
            }

            public function journal(DocumentType $type, Connection $connection): string
            {
                return '90';
            }

            public function costCenter(?string $code, Connection $connection): ?string
            {
                return $code;
            }

            public function costUnit(?string $code, Connection $connection): ?string
            {
                return $code;
            }
        });
    }

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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'sales_invoice',
            'external_id' => 'INV-IDEM-001',
            'number' => '2026-001',
            'issue_date' => '2026-06-16',
            'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'vat_number' => 'NL000099998B57'],
            'lines' => [
                ['description' => 'Consultancy', 'amount' => 200, 'tax_rate' => 21, 'category' => 'omzet'],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postDocument(Consumer $consumer, string $key, array $payload): TestResponse
    {
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/v1/accounting/documents', $payload);
    }

    public function test_replay_returns_the_stored_response_with_a_replay_header(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-1']], 201),
        ]);
        $this->bindFakeReferences();

        $consumer = $this->consumerWithExactConnection();
        $key = (string) Str::uuid();

        $first = $this->postDocument($consumer, $key, $this->payload());
        $second = $this->postDocument($consumer, $key, $this->payload());

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertNull($first->headers->get(EnsureIdempotency::REPLAY_HEADER));
        $this->assertSame('true', $second->headers->get(EnsureIdempotency::REPLAY_HEADER));

        MockClient::global()->assertSentCount(1);
    }

    /**
     * Het bewijs dat de claim vóór de handler staat. Deze assertie zou onder de oude
     * SELECT-dan-INSERT-volgorde falen — er was dan nog geen rij.
     */
    public function test_the_claim_row_is_in_flight_while_the_handler_runs(): void
    {
        $seen = new ArrayObject;

        $this->app->bind(ExactAccountingTarget::class, fn (): AccountingTarget => new class($seen) implements AccountingTarget
        {
            public function __construct(private ArrayObject $seen) {}

            public function push(FinancialDocument $document, Connection $connection): AccountingResult
            {
                $this->seen['row'] = IdempotencyKey::query()->first()?->only(['state', 'response_status', 'locked_at']);

                return new AccountingResult(201, 'inv-guid-observed', null, [], []);
            }
        });

        $consumer = $this->consumerWithExactConnection();

        $this->postDocument($consumer, (string) Str::uuid(), $this->payload())->assertStatus(201);

        $row = $seen['row'] ?? null;

        $this->assertNotNull($row, 'Tijdens de handler hoorde er al een claim-rij te staan.');
        $this->assertSame(IdempotencyKey::STATE_IN_FLIGHT, $row['state']);
        $this->assertNull($row['response_status']);
        $this->assertNotNull($row['locked_at']);
    }

    public function test_the_same_key_with_a_different_payload_is_rejected(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-2']], 201),
        ]);
        $this->bindFakeReferences();

        $consumer = $this->consumerWithExactConnection();
        $key = (string) Str::uuid();

        $this->postDocument($consumer, $key, $this->payload())->assertStatus(201);

        $this->postDocument($consumer, $key, $this->payload(['external_id' => 'INV-ANDERS']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'idempotency_key_reuse');

        MockClient::global()->assertSentCount(1);

        // De bewaarde respons hoort onaangeroerd te zijn.
        $this->assertSame(201, (int) IdempotencyKey::query()->sole()->response_status);
    }

    public function test_a_concurrent_request_with_the_same_key_gets_409(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-3']], 201),
        ]);
        $this->bindFakeReferences();

        $consumer = $this->consumerWithExactConnection();
        $key = (string) Str::uuid();

        // Deterministische stand-in voor de race: de claim staat er al, lease leeft.
        IdempotencyKey::query()->create([
            'consumer_id' => $consumer->getKey(),
            'key' => $key,
            'method' => 'POST',
            'path' => 'v1/accounting/documents',
            'state' => IdempotencyKey::STATE_IN_FLIGHT,
            'request_fingerprint' => null,
            'locked_at' => now(),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
        ]);

        $response = $this->postDocument($consumer, $key, $this->payload());

        $response->assertStatus(409)->assertJsonPath('error', 'idempotency_request_in_progress');
        $this->assertNotNull($response->headers->get('Retry-After'));

        MockClient::global()->assertNothingSent();
    }

    public function test_an_expired_lease_is_taken_over(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-4']], 201),
        ]);
        $this->bindFakeReferences();

        $consumer = $this->consumerWithExactConnection();
        $key = (string) Str::uuid();

        IdempotencyKey::query()->create([
            'consumer_id' => $consumer->getKey(),
            'key' => $key,
            'method' => 'POST',
            'path' => 'v1/accounting/documents',
            'state' => IdempotencyKey::STATE_IN_FLIGHT,
            'request_fingerprint' => null,
            'locked_at' => now()->subSeconds(IdempotencyKey::leaseSeconds() + 60),
            'expires_at' => now()->addDay(),
            'created_at' => now()->subHour(),
        ]);

        $this->postDocument($consumer, $key, $this->payload())->assertStatus(201);

        MockClient::global()->assertSentCount(1);

        $row = IdempotencyKey::query()->sole();
        $this->assertSame(IdempotencyKey::STATE_COMPLETED, $row->state);
        $this->assertSame(201, (int) $row->response_status);
    }

    /**
     * Een mislukte poging mag opnieuw. Zonder vrijgave zou één upstream-storing de
     * sleutel permanent blokkeren.
     */
    public function test_a_failed_request_releases_the_claim(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['error' => ['message' => ['value' => 'stuk']]], 500),
        ]);
        $this->bindFakeReferences();

        $consumer = $this->consumerWithExactConnection();
        $key = (string) Str::uuid();

        $this->postDocument($consumer, $key, $this->payload())->assertStatus(422);

        $this->assertDatabaseCount('idempotency_keys', 0);
    }

    public function test_a_malformed_key_is_rejected_before_the_handler(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'nope']], 201),
        ]);
        $this->bindFakeReferences();

        $consumer = $this->consumerWithExactConnection();

        $this->postDocument($consumer, str_repeat('a', 300), $this->payload())
            ->assertStatus(400)
            ->assertJsonPath('error', 'idempotency_key_invalid');

        MockClient::global()->assertNothingSent();
        $this->assertDatabaseCount('idempotency_keys', 0);
    }

    public function test_a_missing_key_is_still_rejected_with_400(): void
    {
        $consumer = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents', $this->payload())
            ->assertStatus(400)
            ->assertJsonPath('error', 'idempotency_key_required');
    }

    /**
     * Dezelfde sleutel bij twee consumers is geen conflict — de claim is per consumer.
     */
    public function test_the_claim_is_scoped_per_consumer(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-5']], 201),
        ]);
        $this->bindFakeReferences();

        $key = 'gedeelde-sleutel';

        $this->postDocument($this->consumerWithExactConnection(), $key, $this->payload())->assertStatus(201);

        // De auth-manager is een singleton binnen één test en memoiseert de opgeloste
        // user; zonder dit zou het tweede request nog als consumer 1 binnenkomen en
        // zou deze test zijn eigen onderwerp niet raken.
        $this->app['auth']->forgetGuards();

        $this->postDocument($this->consumerWithExactConnection(), $key, $this->payload())->assertStatus(201);

        MockClient::global()->assertSentCount(2);
        $this->assertDatabaseCount('idempotency_keys', 2);
    }

    public function test_a_completed_claim_carries_an_expiry(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-6']], 201),
        ]);
        $this->bindFakeReferences();

        $this->postDocument($this->consumerWithExactConnection(), (string) Str::uuid(), $this->payload())
            ->assertStatus(201);

        $row = IdempotencyKey::query()->sole();

        $this->assertNotNull($row->expires_at);
        $this->assertNotNull($row->completed_at);
        $this->assertTrue($row->expires_at->isFuture());
    }
}
