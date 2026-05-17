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
use App\OAuth\Mollie\MollieConnectOAuthFlow;
use App\OAuth\Testing\FakeOAuthFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertTrue(
            $connection->oauth_state_expires_at->between(
                now()->addMinutes(29),
                now()->addMinutes(31),
            ),
            'oauth_state_expires_at moet ~30 min in toekomst liggen',
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
}
