<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Accounting;

use App\Accounting\AccountingResult;
use App\Accounting\AccountingTargetRegistry;
use App\Accounting\Contracts\AccountingTarget;
use App\Accounting\FinancialDocument;
use App\Enums\Provider;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\Read\GetGlAccounts;
use Emeq\ExactApi\Http\Request\Read\GetJournals;
use Emeq\ExactApi\Http\Request\Read\GetVatCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

class MappingApiTest extends TestCase
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

    /** @return array{0: Consumer, 1: Connection} */
    private function setupConnection(): array
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);
        $connection = Connection::factory()->forExact()->for($account)->create();

        return [$consumer, $connection];
    }

    private function token(Consumer $consumer): string
    {
        return $consumer->createToken('t', [TokenAbilities::EXACT_READ, TokenAbilities::EXACT_WRITE])->plainTextToken;
    }

    public function test_sync_populates_mirror_and_autoderives_mapping(): void
    {
        MockClient::global([
            GetGlAccounts::class => MockResponse::make(['d' => ['results' => [
                ['ID' => 'gl-8000', 'Code' => '8000', 'Description' => 'Omzet'],
                ['ID' => 'gl-4000', 'Code' => '4000', 'Description' => 'Kosten'],
            ]]], 200),
            GetVatCodes::class => MockResponse::make(['d' => ['results' => [
                ['Code' => '3', 'Description' => 'Hoog excl', 'Percentage' => 0.21],
            ]]], 200),
            GetJournals::class => MockResponse::make(['d' => ['results' => [
                ['Code' => '80', 'Description' => 'Verkoopboek', 'Type' => 20],
            ]]], 200),
        ]);
        [$consumer, $connection] = $this->setupConnection();

        $this->withHeader('Authorization', "Bearer {$this->token($consumer)}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/sync')
            ->assertOk()
            ->assertJsonPath('provider', 'exact')
            ->assertJsonPath('synced', 4);

        $this->assertDatabaseHas('connection_accounting_refs', [
            'connection_id' => $connection->getKey(),
            'kind' => 'gl',
            'code' => '8000',
            'native_id' => 'gl-8000',
        ]);

        $mapping = $connection->fresh()->metadata['accounting_mapping'];
        $this->assertSame('3', $mapping['vat_codes']['21']);
        $this->assertSame('80', $mapping['journals']['sales']);
        $this->assertArrayNotHasKey('gl_accounts', $mapping);
    }

    public function test_reference_data_lists_mirror_codes(): void
    {
        [$consumer, $connection] = $this->setupConnection();
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(), 'kind' => 'gl', 'code' => '8000', 'native_id' => 'gl-8000', 'label' => 'Omzet',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token($consumer)}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/accounting/reference-data')
            ->assertOk()
            ->assertJsonPath('gl.0.code', '8000')
            ->assertJsonPath('gl.0.label', 'Omzet');
    }

    public function test_put_mapping_merges_override(): void
    {
        [$consumer, $connection] = $this->setupConnection();
        $connection->metadata = ['accounting_mapping' => ['gl_accounts' => ['omzet' => '8000']]];
        $connection->save();
        foreach (['8100', '4000'] as $code) {
            ConnectionAccountingRef::query()->create([
                'connection_id' => $connection->getKey(), 'kind' => 'gl', 'code' => $code, 'native_id' => "gl-{$code}",
            ]);
        }

        $this->withHeader('Authorization', "Bearer {$this->token($consumer)}")
            ->withHeader('X-Account-Id', 'school1')
            ->putJson('/v1/accounting/mapping', ['gl_accounts' => ['omzet' => '8100', 'kosten' => '4000']])
            ->assertOk()
            ->assertJsonPath('mapping.gl_accounts.omzet', '8100')
            ->assertJsonPath('mapping.gl_accounts.kosten', '4000');
    }

    public function test_put_mapping_keeps_numeric_vat_keys_when_adding_one(): void
    {
        [$consumer, $connection] = $this->setupConnection();
        $connection->metadata = ['accounting_mapping' => ['vat_codes' => ['21' => 'A', '9' => 'B']]];
        $connection->save();

        $this->withHeader('Authorization', "Bearer {$this->token($consumer)}")
            ->withHeader('X-Account-Id', 'school1')
            ->putJson('/v1/accounting/mapping', ['vat_codes' => ['0' => 'C']])
            ->assertOk();

        $vatCodes = $connection->fresh()->metadata['accounting_mapping']['vat_codes'];
        $this->assertEquals(['21' => 'A', '9' => 'B', '0' => 'C'], $vatCodes);
        $this->assertSame('A', $vatCodes['21']);
    }

    public function test_put_mapping_overwrites_an_existing_numeric_vat_key(): void
    {
        [$consumer, $connection] = $this->setupConnection();
        $connection->metadata = ['accounting_mapping' => ['vat_codes' => ['21' => 'A', '9' => 'B']]];
        $connection->save();

        $this->withHeader('Authorization', "Bearer {$this->token($consumer)}")
            ->withHeader('X-Account-Id', 'school1')
            ->putJson('/v1/accounting/mapping', ['vat_codes' => ['21' => 'Z']])
            ->assertOk();

        $this->assertEquals(
            ['21' => 'Z', '9' => 'B'],
            $connection->fresh()->metadata['accounting_mapping']['vat_codes'],
        );
    }

    public function test_put_mapping_keeps_numeric_gl_category_keys(): void
    {
        [$consumer, $connection] = $this->setupConnection();
        $connection->metadata = ['accounting_mapping' => ['gl_accounts' => ['omzet' => '8000', '4500' => '4000']]];
        $connection->save();
        foreach (['8000', '4000', '4100'] as $code) {
            ConnectionAccountingRef::query()->create([
                'connection_id' => $connection->getKey(), 'kind' => 'gl', 'code' => $code, 'native_id' => "gl-{$code}",
            ]);
        }

        $this->withHeader('Authorization', "Bearer {$this->token($consumer)}")
            ->withHeader('X-Account-Id', 'school1')
            ->putJson('/v1/accounting/mapping', ['gl_accounts' => ['4600' => '4100']])
            ->assertOk();

        $this->assertEquals(
            ['omzet' => '8000', '4500' => '4000', '4600' => '4100'],
            $connection->fresh()->metadata['accounting_mapping']['gl_accounts'],
        );
    }

    public function test_put_mapping_is_audited_with_the_consumer_as_actor(): void
    {
        [$consumer, $connection] = $this->setupConnection();
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(), 'kind' => 'gl', 'code' => '4000', 'native_id' => 'gl-4000',
        ]);
        Log::spy();

        $this->withHeader('Authorization', "Bearer {$this->token($consumer)}")
            ->withHeader('X-Account-Id', 'school1')
            ->putJson('/v1/accounting/mapping', ['gl_accounts' => ['kosten' => '4000']])
            ->assertOk();

        Log::shouldHaveReceived('info')->with('accounting.mapping.changed', [
            'connection_id' => $connection->getKey(),
            'section' => 'gl_accounts',
            'key' => 'kosten',
            'old' => null,
            'new' => '4000',
            'actor' => 'consumer:'.$consumer->getKey(),
        ])->once();
    }

    public function test_put_mapping_rejects_gl_code_outside_the_mirror(): void
    {
        [$consumer, $connection] = $this->setupConnection();
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(), 'kind' => 'gl', 'code' => '4000', 'native_id' => 'gl-4000',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token($consumer)}")
            ->withHeader('X-Account-Id', 'school1')
            ->putJson('/v1/accounting/mapping', ['gl_accounts' => ['kosten' => '4000', 'huisvesting' => '9999', 'suspense' => '9998']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gl_accounts.huisvesting', 'gl_accounts.suspense'])
            ->assertJsonMissingValidationErrors(['gl_accounts.kosten']);

        $this->assertNull($connection->fresh()->metadata['accounting_mapping'] ?? null);
    }

    public function test_sync_returns_422_when_the_provider_cannot_sync_references(): void
    {
        app(AccountingTargetRegistry::class)->register(Provider::Snelstart->value, PushOnlySyncTarget::class);

        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);
        Connection::factory()->forSnelstart()->for($account)->create();

        $token = $consumer->createToken('t', [TokenAbilities::ACCOUNTING_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/sync')
            ->assertStatus(422)
            ->assertJsonPath('error', 'sync_unsupported');
    }

    public function test_sync_returns_503_when_the_provider_is_switched_off(): void
    {
        $this->disableProvider('exact');

        [$consumer] = $this->setupConnection();

        $this->withHeader('Authorization', "Bearer {$this->token($consumer)}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/sync')
            ->assertStatus(503)
            ->assertJsonPath('error', 'provider_disabled');
    }
}

final class PushOnlySyncTarget implements AccountingTarget
{
    public function push(FinancialDocument $document, Connection $connection): AccountingResult
    {
        return new AccountingResult(201, 'ref', null, [], []);
    }
}
