<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\Consumer;
use App\Models\PassThroughCall;
use App\Models\ProviderEntityLink;
use App\Support\Connect\ConnectLinkFactory;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

class ConnectManageTest extends TestCase
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

    public function test_manage_url_is_only_offered_for_a_connected_accounting_provider(): void
    {
        $account = $this->account();
        Connection::factory()->forExact()->active()->for($account)->create();
        Connection::factory()->forMollie()->active()->for($account)->create();

        $providers = collect($this->getPageProps($this->linkFor($account))['providers']);

        $this->assertNotNull($providers->firstWhere('key', 'exact')['manage_url']);
        $this->assertNull($providers->firstWhere('key', 'mollie')['manage_url']);
    }

    public function test_the_drawer_payload_carries_the_kopstrip_and_the_three_tabs(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'party-1',
            'native_id' => 'guid-1',
            'label' => 'Acme BV',
            'attrs' => ['matched_on' => 'kvk'],
            'synced_at' => now(),
        ]);

        $this->getJson($this->manageUrlFor($account, 'exact'))
            ->assertOk()
            ->assertJsonPath('connection.provider', 'exact')
            ->assertJsonPath('connection.status', 'active')
            ->assertJsonCount(0, 'bookings')
            ->assertJsonPath('relations.0.code', 'party-1')
            ->assertJsonPath('relations.0.matched_on', 'kvk')
            ->assertJsonStructure(['settings' => ['journals', 'gl_accounts'], 'urls' => ['mapping_url', 'relations_search_url']]);
    }

    public function test_the_bookings_tab_shows_what_the_hub_did_in_the_administration(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();

        ProviderEntityLink::query()->create([
            'connection_id' => $connection->getKey(),
            'provider' => 'exact',
            'entity_type' => 'document',
            'entity_subtype' => 'sales_invoice',
            'external_id' => 'INV-042',
            'provider_entity_id' => 'guid-42',
            'provider_entity_number' => 'Factuur 2026-0042',
            'origin' => ProviderEntityLink::ORIGIN_HUB,
            'last_synced_at' => now(),
        ]);

        PassThroughCall::query()->create([
            'connection_id' => $connection->getKey(),
            'consumer_id' => $account->consumer_id,
            'account_id' => $account->getKey(),
            'provider' => 'exact',
            'method' => 'POST',
            'path' => '/v1/accounting/documents',
            'status' => 201,
            'duration_ms' => 120,
            'request_fingerprint' => substr(hash('sha256', 'INV-042'), 0, 12),
            'warnings' => [['code' => 'relation.created', 'message' => 'Relatie Acme B.V. is aangemaakt.']],
            'created_at' => now(),
        ]);

        $this->getJson($this->manageUrlFor($account, 'exact'))
            ->assertOk()
            ->assertJsonPath('bookings.0.document', 'Factuur 2026-0042')
            ->assertJsonPath('bookings.0.posted', true)
            ->assertJsonPath('bookings.0.messages.0', 'Relatie Acme B.V. is aangemaakt.');
    }

    public function test_a_refused_booking_shows_its_reason(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();

        PassThroughCall::query()->create([
            'connection_id' => $connection->getKey(),
            'consumer_id' => $account->consumer_id,
            'account_id' => $account->getKey(),
            'provider' => 'exact',
            'method' => 'POST',
            'path' => '/v1/accounting/documents',
            'status' => 422,
            'duration_ms' => 90,
            'request_fingerprint' => substr(hash('sha256', 'INV-043'), 0, 12),
            'response_body' => json_encode(['message' => 'Btw-nummer ontbreekt bij Acme B.V.']),
            'created_at' => now(),
        ]);

        $this->getJson($this->manageUrlFor($account, 'exact'))
            ->assertOk()
            ->assertJsonPath('bookings.0.posted', false)
            ->assertJsonPath('bookings.0.document', null)
            ->assertJsonPath('bookings.0.messages.0', 'Btw-nummer ontbreekt bij Acme B.V.');
    }

    public function test_the_drawer_payload_is_rejected_without_a_signature(): void
    {
        $account = $this->account();
        Connection::factory()->forExact()->active()->for($account)->create();

        $this->getJson("/connect/{$account->getKey()}/exact/manage")->assertStatus(403);
    }

    public function test_the_drawer_payload_rejects_a_tampered_account_id(): void
    {
        $mine = $this->account();
        $foreign = Account::factory()->for(Consumer::factory())->create();
        Connection::factory()->forExact()->active()->for($mine)->create();
        Connection::factory()->forExact()->active()->for($foreign)->create();

        $tampered = str_replace(
            "/connect/{$mine->getKey()}/",
            "/connect/{$foreign->getKey()}/",
            $this->manageUrlFor($mine, 'exact'),
        );

        $this->getJson($tampered)->assertStatus(403);
    }

    public function test_a_provider_without_an_accounting_target_is_not_manageable(): void
    {
        $account = $this->account();
        Connection::factory()->forMollie()->active()->for($account)->create();

        $url = URL::temporarySignedRoute('connect.manage.show', now()->addMinutes(15), [
            'account' => $account->getKey(),
            'provider' => 'mollie',
        ]);

        $this->getJson($url)->assertNotFound();
    }

    public function test_saving_the_mapping_only_keeps_the_four_allowed_keys(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();
        $this->syncedJournalAndGl($connection);

        $payload = $this->getJson($this->manageUrlFor($account, 'exact'))->json();

        $this->putJson($payload['urls']['mapping_url'], [
            'journals' => ['sales' => '70', 'purchase' => '80'],
            'gl_accounts' => ['sales_default' => 'gl-8000', 'purchase_default' => 'gl-4000'],
            'vat_codes' => ['21' => '3'],
        ])
            ->assertOk()
            ->assertJsonPath('settings.journals.sales', '70')
            ->assertJsonPath('settings.journals.purchase', '80')
            ->assertJsonPath('settings.gl_accounts.sales_default', 'gl-8000')
            ->assertJsonPath('settings.gl_accounts.purchase_default', 'gl-4000');

        $mapping = $connection->fresh()->metadata['accounting_mapping'];
        $this->assertArrayNotHasKey('vat_codes', $mapping);
        $this->assertSame('70', $mapping['journals']['sales']);
    }

    public function test_saving_the_mapping_rejects_a_code_that_is_not_in_the_mirror(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();
        $this->syncedJournalAndGl($connection);

        $payload = $this->getJson($this->manageUrlFor($account, 'exact'))->json();

        $this->putJson($payload['urls']['mapping_url'], [
            'journals' => ['sales' => 'not-a-real-code'],
        ])->assertStatus(422);
    }

    public function test_relinking_a_relation_updates_the_native_id_and_pins_it(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();
        $ref = ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'party-1',
            'native_id' => 'guid-old',
            'label' => 'Acme BV',
            'attrs' => ['matched_on' => 'name'],
            'synced_at' => now(),
        ]);

        $payload = $this->getJson($this->manageUrlFor($account, 'exact'))->json();
        $relinkUrl = $payload['relations'][0]['relink_url'];

        $this->patchJson($relinkUrl, ['native_id' => 'guid-new', 'label' => 'Acme Holding BV'])
            ->assertOk()
            ->assertJsonPath('relation.native_id', 'guid-new')
            ->assertJsonPath('relation.matched_on', 'pinned');

        $ref->refresh();
        $this->assertSame('guid-new', $ref->native_id);
        $this->assertSame('pinned', $ref->attrs['matched_on']);
    }

    public function test_unlinking_a_relation_removes_the_row(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'party-1',
            'native_id' => 'guid-1',
            'label' => 'Acme BV',
            'attrs' => ['matched_on' => 'created'],
            'synced_at' => now(),
        ]);

        $payload = $this->getJson($this->manageUrlFor($account, 'exact'))->json();
        $unlinkUrl = $payload['relations'][0]['unlink_url'];

        $this->deleteJson($unlinkUrl)->assertOk()->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('connection_accounting_refs', [
            'connection_id' => $connection->getKey(),
            'code' => 'party-1',
        ]);
    }

    public function test_a_relation_action_cannot_reach_another_accounts_connection(): void
    {
        $mine = $this->account();
        $foreign = Account::factory()->for(Consumer::factory())->create();
        Connection::factory()->forExact()->active()->for($mine)->create();
        $foreignConnection = Connection::factory()->forExact()->active()->for($foreign)->create();
        $foreignRef = ConnectionAccountingRef::query()->create([
            'connection_id' => $foreignConnection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'party-1',
            'native_id' => 'guid-1',
            'attrs' => ['matched_on' => 'created'],
            'synced_at' => now(),
        ]);

        $tampered = app(ConnectLinkFactory::class)->manageActionUrl(
            request(),
            $mine,
            'exact',
            'connect.manage.relations.unlink',
            ['ref' => $foreignRef->getKey()],
        );

        $this->deleteJson($tampered)->assertNotFound();
        $this->assertDatabaseHas('connection_accounting_refs', ['id' => $foreignRef->getKey()]);
    }

    public function test_searching_relations_uses_the_exact_name_match(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make([
                'd' => ['results' => [
                    ['ID' => 'guid-9', 'Name' => 'Acme B.V.', 'Code' => '9', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
                ]],
            ], 200),
        ]);

        $account = $this->account();
        Connection::factory()->forExact()->active()->for($account)->create();

        $payload = $this->getJson($this->manageUrlFor($account, 'exact'))->json();

        $this->getJson($payload['urls']['relations_search_url'].'&q=Acme+BV')
            ->assertOk()
            ->assertJsonPath('results.0.id', 'guid-9')
            ->assertJsonPath('results.0.name', 'Acme B.V.');
    }

    private function syncedJournalAndGl(Connection $connection): void
    {
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(), 'kind' => ConnectionAccountingRef::KIND_JOURNAL,
            'code' => '70', 'native_id' => '70', 'label' => 'Verkoop',
        ]);
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(), 'kind' => ConnectionAccountingRef::KIND_JOURNAL,
            'code' => '80', 'native_id' => '80', 'label' => 'Inkoop',
        ]);
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(), 'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => 'gl-8000', 'native_id' => 'gl-8000', 'label' => 'Omzet',
        ]);
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(), 'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => 'gl-4000', 'native_id' => 'gl-4000', 'label' => 'Kosten',
        ]);
    }

    private function account(): Account
    {
        return Account::factory()->for(Consumer::factory())->create();
    }

    private function linkFor(Account $account): string
    {
        return app(ConnectLinkFactory::class)->mint($account)['url'];
    }

    private function manageUrlFor(Account $account, string $provider): string
    {
        return collect($this->getPageProps($this->linkFor($account))['providers'])
            ->firstWhere('key', $provider)['manage_url'];
    }

    /** @return array<string, mixed> */
    private function getPageProps(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }
}
