<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Actions\StartOAuthFlowAction;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\User;
use App\OAuth\Contracts\OAuthFlow;
use App\OAuth\Mollie\MollieConnectOAuthFlow;
use App\OAuth\Testing\FakeOAuthFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plan 08-03 — StartOAuthFlowAction shared Filament Action.
 *
 * D-05 + UI-SPEC S2: één Action-class met forAccount() (primary) +
 * forConnection() (secondary, pending-only). Beide ingangen hergebruiken
 * Phase-4 OAuthFlowRegistry + InitController-logica.
 *
 * Tests dekken:
 *  - RBAC (manage-connections-permission)
 *  - Visibility-conditions (provider/access_token/revoked_at op forConnection)
 *  - Descriptor-driven oauthCapableProviders() — alleen providers met oauthFlowKey
 *  - Action submit creëert pending Connection met 48-char state + 30-min TTL
 *  - Redirect-URL bevat authorize-host + state-parameter
 */
class StartOAuthFlowActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind FakeOAuthFlow zodat we Mollie-call kunnen onderscheppen voor URL-assertions
        // en geen externe HTTP doen tijdens visibility-tests.
        $this->app->bind(MollieConnectOAuthFlow::class, FakeOAuthFlow::class);
    }

    private function seedRolesAndPermissions(): void
    {
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-connections']);
        // manage-consumers nodig voor AccountResource::canAccess() in mount-tests
        Permission::firstOrCreate(['name' => 'manage-consumers']);
    }

    private function staffUserWithPermission(): User
    {
        $this->seedRolesAndPermissions();
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('manage-connections');

        return $user;
    }

    private function staffUserWithoutPermission(): User
    {
        $this->seedRolesAndPermissions();
        $user = User::factory()->create();
        $user->assignRole('staff');

        return $user;
    }

    private function makeAccount(): Account
    {
        $consumer = Consumer::factory()->create();

        return Account::factory()->for($consumer)->create();
    }

    // ============================================================
    // Test 1-2: forAccount() visibility — RBAC via manage-connections
    // ============================================================

    public function test_account_action_visible_for_staff_with_manage_connections(): void
    {
        $this->actingAs($this->staffUserWithPermission());

        $action = StartOAuthFlowAction::forAccount();
        $action->record($this->makeAccount());

        $this->assertTrue($action->isVisible());
    }

    public function test_account_action_hidden_for_staff_without_manage_connections(): void
    {
        $this->actingAs($this->staffUserWithoutPermission());

        $action = StartOAuthFlowAction::forAccount();
        $action->record($this->makeAccount());

        $this->assertFalse($action->isVisible());
    }

    // ============================================================
    // Test 3-6: forConnection() visibility — provider + state-guards
    // ============================================================

    public function test_connection_action_visible_for_pending_mollie_connection(): void
    {
        $this->actingAs($this->staffUserWithPermission());

        $account = $this->makeAccount();
        $pending = Connection::factory()->pending()->for($account)->create();

        $action = StartOAuthFlowAction::forConnection();
        $action->record($pending);

        $this->assertTrue($action->isVisible());
    }

    public function test_connection_action_hidden_when_access_token_present(): void
    {
        $this->actingAs($this->staffUserWithPermission());

        $account = $this->makeAccount();
        $active = Connection::factory()->forMollie()->for($account)->create();

        $action = StartOAuthFlowAction::forConnection();
        $action->record($active);

        $this->assertFalse($action->isVisible());
    }

    public function test_connection_action_hidden_when_revoked(): void
    {
        $this->actingAs($this->staffUserWithPermission());

        $account = $this->makeAccount();
        $revoked = Connection::factory()->forMollie()->for($account)->create([
            'access_token' => null,
            'revoked_at' => now(),
            'status' => 'revoked',
        ]);

        $action = StartOAuthFlowAction::forConnection();
        $action->record($revoked);

        $this->assertFalse($action->isVisible());
    }

    public function test_connection_action_hidden_for_non_mollie_provider(): void
    {
        $this->actingAs($this->staffUserWithPermission());

        $account = $this->makeAccount();
        $snelstart = Connection::factory()->forSnelstart()->for($account)->create();

        $action = StartOAuthFlowAction::forConnection();
        $action->record($snelstart);

        $this->assertFalse($action->isVisible());
    }

    // ============================================================
    // Test 7: oauthCapableProviders() — descriptor-driven whitelist
    // ============================================================

    public function test_oauth_capable_providers_only_returns_providers_with_oauth_flow(): void
    {
        $providers = StartOAuthFlowAction::oauthCapableProviders();

        // Mollie heeft oauth_flow_key='mollie' in config/hub-providers.php
        $this->assertArrayHasKey('mollie', $providers);

        // Snelstart heeft oauth_flow_key=null → moet ontbreken
        $this->assertArrayNotHasKey('snelstart', $providers);
    }

    // ============================================================
    // Test 8: action dispatch creates pending Connection
    // ============================================================

    public function test_dispatch_creates_pending_connection_for_account(): void
    {
        // WR-01: freeze de klok om de ~30-min-TTL-assertion deterministisch te maken.
        // De vorige between()-vorm woog twee losse now()-calls (~ms-drift) tegen elkaar
        // af en kon op trage CI runners flaken.
        Date::setTestNow('2026-05-17 12:00:00');

        $account = $this->makeAccount();

        StartOAuthFlowAction::dispatch($account, 'mollie');

        $this->assertDatabaseHas('connections', [
            'account_id' => $account->id,
            'provider' => 'mollie',
            'status' => 'pending',
        ]);

        $connection = Connection::where('account_id', $account->id)->first();
        $this->assertNotNull($connection);
        $this->assertSame(48, strlen((string) $connection->oauth_state));
        $this->assertNotNull($connection->oauth_state_expires_at);
        $this->assertSame(
            now()->addMinutes(30)->format('Y-m-d H:i:s'),
            $connection->oauth_state_expires_at->format('Y-m-d H:i:s'),
            'oauth_state_expires_at moet exact 30 min in toekomst liggen',
        );
    }

    // ============================================================
    // Test 9: dispatch returns redirect with authorize URL + state
    // ============================================================

    public function test_dispatch_returns_redirect_to_authorize_url_with_state(): void
    {
        $account = $this->makeAccount();

        $response = StartOAuthFlowAction::dispatch($account, 'mollie');

        $connection = Connection::where('account_id', $account->id)->first();
        $this->assertNotNull($connection);

        // FakeOAuthFlow retourneert 'https://fake.oauth.local/authorize?state=<state>'
        // — bewijst dat dispatch() de Registry's OAuthFlow aanroept met state-param.
        $expectedUrl = 'https://fake.oauth.local/authorize?state='.$connection->oauth_state;
        $this->assertSame($expectedUrl, $response->getTargetUrl());
    }

    // ============================================================
    // Regressie: provider zonder scopes (Exact) — dispatch mocht NIET breken
    // op config("services.{provider}.connect.scopes") == null. De Filament-action
    // is het echte UI-pad (anders dan ExactInitController, die [] hardcodeert).
    // ============================================================

    public function test_dispatch_for_exact_builds_authorize_redirect(): void
    {
        config([
            'services.exact.client_id' => 'app_test_id',
            'services.exact.redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
        ]);

        $account = $this->makeAccount();

        // Echte ExactOAuthFlow (niet gefaket) — getAuthorizationUrl bouwt enkel een
        // string, geen HTTP. Vóór de fix gaf config('services.exact.connect.scopes')
        // null → getAuthorizationUrl(array $scopes) TypeError → back().
        $response = StartOAuthFlowAction::dispatch($account, 'exact');

        $this->assertStringStartsWith('https://start.exactonline.nl/api/oauth2/auth', $response->getTargetUrl());
        $this->assertStringContainsString('client_id=app_test_id', $response->getTargetUrl());
        $this->assertStringNotContainsString('scope=', $response->getTargetUrl());

        $this->assertDatabaseHas('connections', [
            'account_id' => $account->id,
            'provider' => 'exact',
            'status' => 'pending',
        ]);
    }

    // ============================================================
    // Regressie: dispatch() moet idempotent zijn. Een bestaande niet-revoked
    // Connection (bv. orphan pending van een eerdere mislukte poging) mag NIET
    // een tweede insert triggeren — de partial unique-index
    // (account_id, provider) WHERE revoked_at IS NULL weigert dat anders.
    // ============================================================

    public function test_dispatch_reuses_existing_non_revoked_connection(): void
    {
        config([
            'services.exact.client_id' => 'app_test_id',
            'services.exact.redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
        ]);

        $account = $this->makeAccount();
        $existing = Connection::factory()->forExact()->create([
            'account_id' => $account->id,
            'status' => 'pending',
            'oauth_state' => 'oude-state',
            'access_token' => null,
        ]);

        $response = StartOAuthFlowAction::dispatch($account, 'exact');

        // Geen tweede rij — de bestaande is hergebruikt.
        $this->assertSame(
            1,
            Connection::query()->where('account_id', $account->id)->where('provider', 'exact')->count(),
        );

        $existing->refresh();
        $this->assertSame('pending', $existing->status);
        $this->assertNotSame('oude-state', $existing->oauth_state);
        $this->assertStringStartsWith('https://start.exactonline.nl/api/oauth2/auth', $response->getTargetUrl());
    }

    // ============================================================
    // Regressie: door Livewire heen (niet directe HTTP-call) geeft redirect()
    // een Livewire\...\Redirector i.p.v. RedirectResponse — dispatch()'s
    // return-type moet dat accepteren. Dit was het gat dat de directe
    // dispatch()-test miste.
    // ============================================================

    public function test_account_action_through_livewire_redirects_for_exact(): void
    {
        config([
            'services.exact.client_id' => 'app_test_id',
            'services.exact.redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
        ]);

        $staff = $this->staffUserWithPermission();
        $staff->givePermissionTo('manage-consumers');
        $this->actingAs($staff);

        $account = $this->makeAccount();

        Livewire::test(ListAccounts::class)
            ->callTableAction('startOAuthFlow', $account, data: ['provider' => 'exact'])
            ->assertRedirect();

        $this->assertDatabaseHas('connections', [
            'account_id' => $account->id,
            'provider' => 'exact',
            'status' => 'pending',
        ]);
    }

    // ============================================================
    // Task 2 mount-tests — wiring op ConnectionResource + AccountResource
    // ============================================================

    public function test_connection_resource_mounts_start_oauth_flow_action_on_pending_mollie(): void
    {
        $staff = $this->staffUserWithPermission();
        $this->actingAs($staff);

        $account = $this->makeAccount();
        $pending = Connection::factory()->pending()->for($account)->create();

        Livewire::test(ListConnections::class)
            ->assertTableActionVisible('startOAuthFlow', $pending);
    }

    public function test_connection_resource_start_oauth_flow_hidden_when_access_token_present(): void
    {
        $staff = $this->staffUserWithPermission();
        $this->actingAs($staff);

        $account = $this->makeAccount();
        $active = Connection::factory()->forMollie()->for($account)->create();

        Livewire::test(ListConnections::class)
            ->assertTableActionHidden('startOAuthFlow', $active);
    }

    public function test_connection_resource_revoke_action_remains_intact_after_mount(): void
    {
        $staff = $this->staffUserWithPermission();
        $this->actingAs($staff);

        $account = $this->makeAccount();
        $mollie = Connection::factory()->forMollie()->for($account)->create();

        // Bestaande Phase-9 revoke-action moet zichtbaar blijven naast nieuwe
        // startOAuthFlow-action — regressie-bewijs voor 09-06 wiring.
        Livewire::test(ListConnections::class)
            ->assertTableActionVisible('revoke', $mollie);
    }

    public function test_account_resource_mounts_start_oauth_flow_action_for_staff(): void
    {
        $staff = $this->staffUserWithPermission();
        // AccountResource::canAccess() vereist manage-consumers
        $staff->givePermissionTo('manage-consumers');
        $this->actingAs($staff);

        $account = $this->makeAccount();

        Livewire::test(ListAccounts::class)
            ->assertTableActionVisible('startOAuthFlow', $account);
    }

    public function test_account_resource_start_oauth_flow_hidden_without_manage_connections(): void
    {
        $this->seedRolesAndPermissions();
        $user = User::factory()->create();
        $user->assignRole('staff');
        // Geef manage-consumers (voor AccountResource::canAccess) maar GEEN manage-connections
        $user->givePermissionTo('manage-consumers');
        $this->actingAs($user);

        $account = $this->makeAccount();

        Livewire::test(ListAccounts::class)
            ->assertTableActionHidden('startOAuthFlow', $account);
    }

    // ============================================================
    // CR-03 regression: dispatch() degradeert netjes als Pennant flag inactive is
    // ============================================================

    public function test_dispatch_returns_back_with_notification_when_provider_disabled(): void
    {
        $account = $this->makeAccount();

        // Pennant kill-switch op Mollie zetten — `OAuthFlowRegistry::for('mollie')`
        // gooit nu een ProviderDisabledException. Voorheen catchte dispatch() alleen
        // InvalidArgumentException → 500.
        Feature::define('provider-mollie-enabled', fn () => false);

        $response = StartOAuthFlowAction::dispatch($account, 'mollie');

        // Geen orphan pending row mag worden aangemaakt
        $this->assertSame(0, Connection::query()->count(), 'Disabled provider mag geen Connection inserten');

        // back()-redirect i.p.v. 500
        $this->assertSame(302, $response->getStatusCode());
    }

    public function test_oauth_capable_providers_excludes_disabled_providers(): void
    {
        // Met Mollie disabled mag het uit de dropdown verdwijnen (geen race tussen
        // form-show en form-submit).
        Feature::define('provider-mollie-enabled', fn () => false);

        $providers = StartOAuthFlowAction::oauthCapableProviders();

        $this->assertArrayNotHasKey('mollie', $providers);
    }

    // ============================================================
    // CR-04-equivalent regression: orphan pending Connection bij
    // getAuthorizationUrl() failure mag niet voorkomen.
    // ============================================================

    public function test_dispatch_does_not_create_orphan_connection_when_authorize_url_throws(): void
    {
        $account = $this->makeAccount();

        // Bind een OAuth-flow die in getAuthorizationUrl() throw't — bewijst dat
        // dispatch() de Connection NIET wegschrijft als de authorize-URL faalt.
        $this->app->bind(MollieConnectOAuthFlow::class, function () {
            return new class implements OAuthFlow
            {
                public function getAuthorizationUrl(Account $account, array $scopes, string $state): string
                {
                    throw new \RuntimeException('Network down.');
                }

                public function exchangeCode(Connection $connection, string $code): Connection
                {
                    return $connection;
                }

                public function refreshToken(Connection $connection): Connection
                {
                    return $connection;
                }

                public function revoke(Connection $connection): void {}
            };
        });

        $response = StartOAuthFlowAction::dispatch($account, 'mollie');

        // Geen orphan pending row — dat was de bug pre-fix.
        $this->assertSame(0, Connection::query()->count(), 'Geen orphan pending row als authorize-URL faalt');

        // En een back()-redirect i.p.v. 500.
        $this->assertSame(302, $response->getStatusCode());
    }
}
