<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\Consumer;
use App\Models\PassThroughCall;
use App\Models\ProviderEntityLink;
use App\Sanctum\TokenAbilities;
use App\Support\Connect\ConnectLinkFactory;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Emeq\ExactApi\Http\Request\Write\CreateSalesEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
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

    public function test_the_settings_payload_carries_the_basis_accounts_and_the_mode(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create([
            'metadata' => ['accounting_mapping' => [
                'journals' => ['sales' => '70'],
                'gl_accounts' => ['invoice' => 'gl-8000', 'suspense' => 'gl-4000', 'sales_default' => 'gl-8000', 'purchase_default' => 'gl-4000'],
            ]],
        ]);
        $this->syncedJournalAndGl($connection);

        $this->getJson($this->manageUrlFor($account, 'exact'))
            ->assertOk()
            ->assertJsonPath('settings.mode', 'manage')
            ->assertJsonPath('settings.journals.sales', '70')
            ->assertJsonPath('settings.gl_accounts.invoice', 'gl-8000')
            ->assertJsonPath('settings.gl_accounts.self_billing', null)
            ->assertJsonPath('settings.gl_accounts.suspense', 'gl-4000')
            ->assertJsonPath('settings.gl_accounts.options.0.code', 'gl-4000')
            ->assertJsonMissingPath('settings.gl_accounts.sales_default')
            ->assertJsonMissingPath('settings.gl_accounts.purchase_default');
    }

    public function test_the_vat_rows_list_every_fixed_rate_with_the_mirror_options(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create([
            'metadata' => ['accounting_mapping' => ['vat_codes' => ['21' => '4']]],
        ]);
        $this->syncedVat($connection);

        $settings = $this->getJson($this->manageUrlFor($account, 'exact'))->assertOk()->json('settings');

        $this->assertSame([
            ['key' => '21', 'label' => '21%', 'value' => '4'],
            ['key' => '9', 'label' => '9%', 'value' => null],
            ['key' => '0', 'label' => '0%', 'value' => null],
            ['key' => 'reverse_charge:21', 'label' => '21% verlegd', 'value' => null],
            ['key' => 'reverse_charge:9', 'label' => '9% verlegd', 'value' => null],
        ], $settings['vat_codes']);
        $this->assertSame([
            ['code' => '4', 'label' => '4 · BTW 21%'],
            ['code' => '6', 'label' => '6 · BTW 21% verlegd'],
        ], $settings['vat_options']);
    }

    public function test_the_categories_list_unmapped_first_with_a_name_suggestion_and_shows_orphans(): void
    {
        $account = $this->account();
        $account->update(['accounting_categories' => [
            ['key' => 'income:1', 'label' => 'Omzet', 'type' => 'income'],
            ['key' => 'expense:12', 'label' => 'Brandstof', 'type' => 'expense'],
            ['key' => 'expense:13', 'label' => 'Telefoon', 'type' => 'expense'],
            ['key' => 'expense:14', 'label' => 'Kantoor huur', 'type' => 'expense'],
            ['key' => 'expense:15', 'label' => 'Café', 'type' => 'expense'],
            ['key' => 'expense:16', 'label' => 'Tol en parking', 'type' => 'expense'],
        ]]);
        $connection = Connection::factory()->forExact()->active()->for($account)->create([
            'metadata' => ['accounting_mapping' => ['gl_accounts' => [
                'income:1' => '8000',
                'expense:99' => '4800',
                'invoice' => '8000',
                'self_billing' => '8000',
                'suspense' => '4800',
                'sales_default' => '8000',
                'purchase_default' => '4800',
                '_default' => '8000',
            ]]],
        ]);
        foreach (['4400' => 'Huur pand', '4410' => 'Kantoor', '4500' => "Brandstof auto's", '4700' => 'Cafe bezoek', '4800' => 'Rente en kosten', '8000' => 'Omzet'] as $code => $label) {
            ConnectionAccountingRef::query()->create([
                'connection_id' => $connection->getKey(), 'kind' => ConnectionAccountingRef::KIND_GL,
                'code' => (string) $code, 'native_id' => "guid-{$code}", 'label' => $label,
            ]);
        }

        $this->assertSame([
            ['key' => 'expense:12', 'label' => 'Brandstof', 'type' => 'expense', 'gl_account' => null, 'suggestion' => '4500', 'orphaned' => false],
            ['key' => 'expense:13', 'label' => 'Telefoon', 'type' => 'expense', 'gl_account' => null, 'suggestion' => null, 'orphaned' => false],
            ['key' => 'expense:14', 'label' => 'Kantoor huur', 'type' => 'expense', 'gl_account' => null, 'suggestion' => '4400', 'orphaned' => false],
            ['key' => 'expense:15', 'label' => 'Café', 'type' => 'expense', 'gl_account' => null, 'suggestion' => '4700', 'orphaned' => false],
            ['key' => 'expense:16', 'label' => 'Tol en parking', 'type' => 'expense', 'gl_account' => null, 'suggestion' => null, 'orphaned' => false],
            ['key' => 'income:1', 'label' => 'Omzet', 'type' => 'income', 'gl_account' => '8000', 'suggestion' => null, 'orphaned' => false],
            ['key' => 'expense:99', 'label' => 'expense:99', 'type' => null, 'gl_account' => '4800', 'suggestion' => null, 'orphaned' => true],
        ], $this->getJson($this->manageUrlFor($account, 'exact'))->assertOk()->json('settings.categories'));
    }

    public function test_the_categories_are_empty_without_a_snapshot_or_category_entries(): void
    {
        $account = $this->account();
        Connection::factory()->forExact()->active()->for($account)->create([
            'metadata' => ['accounting_mapping' => ['gl_accounts' => ['invoice' => 'gl-8000', 'sales_default' => 'gl-8000']]],
        ]);

        $this->getJson($this->manageUrlFor($account, 'exact'))
            ->assertOk()
            ->assertJsonPath('settings.categories', []);
    }

    public function test_a_view_link_reports_view_mode_in_the_settings(): void
    {
        $account = $this->account();
        Connection::factory()->forExact()->active()->for($account)->create();

        $exact = collect($this->getPageProps($this->mintViaApi($account, ['mode' => 'view']))['providers'])
            ->firstWhere('key', 'exact');

        $this->getJson($exact['manage_url'])->assertOk()->assertJsonPath('settings.mode', 'view');
    }

    public function test_saving_the_mapping_leaves_the_type_defaults_untouched(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create([
            'metadata' => ['accounting_mapping' => ['gl_accounts' => ['sales_default' => 'gl-8000']]],
        ]);
        $this->syncedJournalAndGl($connection);

        $payload = $this->getJson($this->manageUrlFor($account, 'exact'))->json();

        $this->putJson($payload['urls']['mapping_url'], [
            'journals' => ['sales' => '70', 'purchase' => '80'],
            'gl_accounts' => ['sales_default' => 'gl-4000', 'purchase_default' => 'gl-4000'],
        ])
            ->assertOk()
            ->assertJsonPath('settings.journals.sales', '70')
            ->assertJsonMissingPath('settings.gl_accounts.sales_default');

        $mapping = $connection->fresh()->metadata['accounting_mapping'];
        $this->assertSame('70', $mapping['journals']['sales']);
        $this->assertSame(['sales_default' => 'gl-8000'], $mapping['gl_accounts']);
    }

    public function test_confirming_writes_journals_vat_codes_and_gl_accounts_in_one_update(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create([
            'metadata' => ['accounting_mapping' => ['gl_accounts' => ['expense:7' => 'gl-4000', 'sales_default' => 'gl-8000']]],
        ]);
        $this->syncedJournalAndGl($connection);
        $this->syncedVat($connection);

        $mappingUrl = $this->getJson($this->manageUrlFor($account, 'exact'))->json('urls.mapping_url');

        $this->putJson($mappingUrl, [
            'journals' => ['sales' => '70', 'purchase' => '80'],
            'vat_codes' => ['21' => '4', 'reverse_charge:21' => '6', 'unknown' => '4'],
            'gl_accounts' => ['invoice' => 'gl-8000', 'self_billing' => 'gl-4000', 'suspense' => 'gl-4000', 'expense:12' => 'gl-4000', 'expense:7' => null],
        ])->assertOk();

        $this->assertEquals([
            'gl_accounts' => ['sales_default' => 'gl-8000', 'invoice' => 'gl-8000', 'self_billing' => 'gl-4000', 'suspense' => 'gl-4000', 'expense:12' => 'gl-4000'],
            'journals' => ['sales' => '70', 'purchase' => '80'],
            'vat_codes' => ['21' => '4', 'reverse_charge:21' => '6'],
        ], $connection->fresh()->metadata['accounting_mapping']);
    }

    public function test_confirming_with_codes_outside_the_mirror_saves_nothing_and_names_each_field(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();
        $this->syncedJournalAndGl($connection);
        $this->syncedVat($connection);

        $mappingUrl = $this->getJson($this->manageUrlFor($account, 'exact'))->json('urls.mapping_url');

        $this->putJson($mappingUrl, [
            'journals' => ['sales' => '70'],
            'vat_codes' => ['21' => '99'],
            'gl_accounts' => ['invoice' => 'gl-8000', 'expense:12' => 'gl-9999'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vat_codes.21', 'gl_accounts.expense:12'])
            ->assertJsonMissingValidationErrors(['journals.sales', 'gl_accounts.invoice']);

        $this->assertArrayNotHasKey('accounting_mapping', $connection->fresh()->metadata ?? []);
    }

    public function test_a_category_key_with_a_dot_is_rejected(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();
        $this->syncedJournalAndGl($connection);

        $mappingUrl = $this->getJson($this->manageUrlFor($account, 'exact'))->json('urls.mapping_url');

        $this->putJson($mappingUrl, ['gl_accounts' => ['expense.12' => 'gl-4000']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gl_accounts']);

        $this->assertArrayNotHasKey('accounting_mapping', $connection->fresh()->metadata ?? []);
    }

    public function test_a_multi_key_confirm_logs_one_change_per_key_with_the_actor(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();
        $this->syncedJournalAndGl($connection);

        $exact = collect($this->getPageProps($this->mintViaApi($account, ['actor' => ['name' => 'Jan', 'email' => 'jan@rijschool.test']]))['providers'])
            ->firstWhere('key', 'exact');
        $mappingUrl = $this->getJson($exact['manage_url'])->json('urls.mapping_url');

        Log::spy();

        $this->putJson($mappingUrl, [
            'journals' => ['sales' => '70'],
            'gl_accounts' => ['suspense' => 'gl-4000', 'expense:12' => 'gl-4000'],
        ])->assertOk();

        $actor = 'consumer:'.substr(hash('sha256', 'jan@rijschool.test'), 0, 12);

        foreach ([['journals', 'sales'], ['gl_accounts', 'suspense'], ['gl_accounts', 'expense:12']] as [$section, $key]) {
            Log::shouldHaveReceived('info')->with('accounting.mapping.changed', \Mockery::on(
                fn (array $context): bool => $context['section'] === $section && $context['key'] === $key && $context['actor'] === $actor,
            ))->once();
        }

        Log::shouldHaveReceived('info')->with('accounting.mapping.changed', \Mockery::any())->times(3);
    }

    public function test_a_category_mapped_in_the_drawer_books_on_the_chosen_gl_account(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
        ]);

        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create([
            'expires_at' => now()->addMinutes(10),
            'metadata' => ['accounting_mapping' => ['vat_codes' => ['21' => '4'], 'journals' => ['sales' => '70']]],
        ]);
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(), 'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => '4500', 'native_id' => 'guid-4500', 'label' => 'Brandstof',
        ]);
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(), 'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'acme-1', 'native_id' => 'cust-real',
        ]);

        $token = $account->consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;
        $book = fn () => $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $account->external_id)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', [
                'type' => 'sales_invoice',
                'external_id' => 'INV-2026-001',
                'issue_date' => '2026-06-16',
                'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'kind' => 'company', 'external_id' => 'acme-1', 'vat_number' => 'NL000099998B57'],
                'lines' => [
                    ['description' => 'Tanken', 'amount' => 50, 'tax_rate' => 21, 'category' => 'expense:12', 'category_label' => 'Brandstof'],
                ],
            ]);

        $book()->assertStatus(422)->assertJsonPath('error', 'mapping_failed');

        $mappingUrl = $this->getJson($this->manageUrlFor($account, 'exact'))->json('urls.mapping_url');
        $this->putJson($mappingUrl, ['gl_accounts' => ['expense:12' => '4500']])->assertOk();

        $book()->assertStatus(201);

        MockClient::global()->assertSent(fn (CreateSalesEntry $request): bool => $request->body()->all()['SalesEntryLines'][0]['GLAccount'] === 'guid-4500');
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

    public function test_a_view_link_refuses_every_mutation(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();
        $this->syncedJournalAndGl($connection);
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'party-1',
            'native_id' => 'guid-1',
            'synced_at' => now(),
        ]);

        $exact = collect($this->getPageProps($this->mintViaApi($account, ['mode' => 'view']))['providers'])
            ->firstWhere('key', 'exact');
        $drawer = $this->getJson($exact['manage_url'])->assertOk()->json();

        $this->post($exact['start_url'])->assertForbidden();
        $this->delete($exact['disconnect_url'])->assertForbidden();
        $this->putJson($drawer['urls']['mapping_url'], ['journals' => ['sales' => '70']])->assertForbidden();
        $this->patchJson($drawer['relations'][0]['relink_url'], ['native_id' => 'guid-new'])->assertForbidden();
        $this->deleteJson($drawer['relations'][0]['unlink_url'])->assertForbidden();

        $this->assertNull($connection->fresh()->revoked_at);
        $this->assertArrayNotHasKey('accounting_mapping', $connection->fresh()->metadata ?? []);
        $this->assertDatabaseHas('connection_accounting_refs', ['code' => 'party-1', 'native_id' => 'guid-1']);
    }

    public function test_stripping_or_changing_the_view_mode_breaks_the_signature(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();
        $this->syncedJournalAndGl($connection);

        $exact = collect($this->getPageProps($this->mintViaApi($account, ['mode' => 'view']))['providers'])
            ->firstWhere('key', 'exact');
        $mappingUrl = $this->getJson($exact['manage_url'])->assertOk()->json('urls.mapping_url');

        $stripped = preg_replace('/mode=view&?/', '', $mappingUrl);
        $changed = str_replace('mode=view', 'mode=manage', $mappingUrl);

        foreach ([$stripped, $changed] as $tampered) {
            $this->assertNotSame($mappingUrl, $tampered);
            $this->assertStringNotContainsString('mode=view', $tampered);
            $this->putJson($tampered, ['journals' => ['sales' => '70']])->assertForbidden();
        }

        $this->assertArrayNotHasKey('accounting_mapping', $connection->fresh()->metadata ?? []);
    }

    public function test_a_manage_link_still_allows_mutations(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();
        $this->syncedJournalAndGl($connection);

        $exact = collect($this->getPageProps($this->mintViaApi($account, ['mode' => 'manage']))['providers'])
            ->firstWhere('key', 'exact');
        $drawer = $this->getJson($exact['manage_url'])->assertOk()->json();

        $this->putJson($drawer['urls']['mapping_url'], ['journals' => ['sales' => '70']])->assertOk();
        $this->assertSame('70', $connection->fresh()->metadata['accounting_mapping']['journals']['sales']);
    }

    public function test_a_mapping_change_via_the_drawer_logs_the_actor_from_the_link(): void
    {
        $account = $this->account();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();
        $this->syncedJournalAndGl($connection);
        $actor = ['name' => 'Jan Jansen', 'email' => ' Jan@Rijschool.test '];

        $exact = collect($this->getPageProps($this->mintViaApi($account, ['actor' => $actor]))['providers'])
            ->firstWhere('key', 'exact');
        $mappingUrl = $this->getJson($exact['manage_url'])->assertOk()->json('urls.mapping_url');

        foreach ([$exact['manage_url'], $mappingUrl] as $url) {
            $this->assertStringNotContainsStringIgnoringCase('rijschool.test', urldecode($url));
            $this->assertStringNotContainsStringIgnoringCase('jansen', urldecode($url));
        }

        $forged = preg_replace('/actor=[^&]+/', 'actor=consumer%3Aforged', $mappingUrl);
        $this->assertNotSame($mappingUrl, $forged);
        $this->putJson($forged, ['journals' => ['sales' => '70']])->assertForbidden();

        Log::spy();

        $this->putJson($mappingUrl, ['journals' => ['sales' => '70']])->assertOk();

        $fingerprint = 'consumer:'.substr(hash('sha256', 'jan@rijschool.test'), 0, 12);

        Log::shouldHaveReceived('info')->with('accounting.mapping.changed', \Mockery::on(function (array $context) use ($connection, $fingerprint): bool {
            $flat = strtolower((string) json_encode($context));

            return $context === [
                'connection_id' => $connection->getKey(),
                'section' => 'journals',
                'key' => 'sales',
                'old' => null,
                'new' => '70',
                'actor' => $fingerprint,
            ] && ! str_contains($flat, 'rijschool.test') && ! str_contains($flat, 'jan jansen');
        }))->once();
    }

    public function test_the_actor_survives_the_redirect_after_a_disconnect(): void
    {
        $account = $this->account();
        Connection::factory()->forExact()->active()->for($account)->create();

        $exact = collect($this->getPageProps($this->mintViaApi($account, ['actor' => ['name' => 'Jan', 'email' => 'jan@rijschool.test']]))['providers'])
            ->firstWhere('key', 'exact');
        parse_str((string) parse_url($exact['disconnect_url'], PHP_URL_QUERY), $query);

        $redirect = $this->delete($exact['disconnect_url'])->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $redirectQuery);

        $this->assertSame($query['actor'], $redirectQuery['actor'] ?? null);
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

    private function syncedVat(Connection $connection): void
    {
        foreach (['4' => 'BTW 21%', '6' => 'BTW 21% verlegd'] as $code => $label) {
            ConnectionAccountingRef::query()->create([
                'connection_id' => $connection->getKey(), 'kind' => ConnectionAccountingRef::KIND_VAT,
                'code' => (string) $code, 'native_id' => (string) $code, 'label' => $label,
            ]);
        }
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

    /** @param  array<string, mixed>  $payload */
    private function mintViaApi(Account $account, array $payload = []): string
    {
        $token = $account->consumer->createToken('t', [TokenAbilities::INTEGRATIONS_MANAGE])->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connect-sessions', ['account_external_id' => $account->external_id, ...$payload])
            ->assertOk()
            ->json('url');
    }

    /** @return array<string, mixed> */
    private function getPageProps(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }
}
