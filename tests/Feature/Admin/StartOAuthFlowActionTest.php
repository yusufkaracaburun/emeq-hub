<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Actions\StartOAuthFlowAction;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Connections\Pages\ViewConnection;
use App\Integrations\Contracts\OAuthFlow;
use App\Integrations\Mollie\OAuth\MollieConnectOAuthFlow;
use App\Integrations\OAuth\Testing\FakeOAuthFlow;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StartOAuthFlowActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(MollieConnectOAuthFlow::class, FakeOAuthFlow::class);
    }

    private function seedRolesAndPermissions(): void
    {
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-connections']);
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

    public function test_oauth_capable_providers_only_returns_providers_with_oauth_flow(): void
    {
        $providers = StartOAuthFlowAction::oauthCapableProviders();

        $this->assertArrayHasKey('mollie', $providers);

        $this->assertArrayNotHasKey('snelstart', $providers);
    }

    public function test_dispatch_creates_pending_connection_for_account(): void
    {
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

    public function test_dispatch_returns_redirect_to_authorize_url_with_state(): void
    {
        $account = $this->makeAccount();

        $response = StartOAuthFlowAction::dispatch($account, 'mollie');

        $connection = Connection::where('account_id', $account->id)->first();
        $this->assertNotNull($connection);

        $expectedUrl = 'https://fake.oauth.local/authorize?state='.$connection->oauth_state;
        $this->assertSame($expectedUrl, $response->getTargetUrl());
    }

    public function test_dispatch_for_exact_builds_authorize_redirect(): void
    {
        config([
            'services.exact.client_id' => 'app_test_id',
            'services.exact.redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
        ]);

        $account = $this->makeAccount();

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

        $this->assertSame(
            1,
            Connection::query()->where('account_id', $account->id)->where('provider', 'exact')->count(),
        );

        $existing->refresh();
        $this->assertSame('pending', $existing->status);
        $this->assertNotSame('oude-state', $existing->oauth_state);
        $this->assertStringStartsWith('https://start.exactonline.nl/api/oauth2/auth', $response->getTargetUrl());
    }

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

    public function test_connection_resource_mounts_start_oauth_flow_action_on_pending_mollie(): void
    {
        $staff = $this->staffUserWithPermission();
        $this->actingAs($staff);

        $account = $this->makeAccount();
        $pending = Connection::factory()->pending()->for($account)->create();

        Livewire::test(ViewConnection::class, ['record' => $pending->getRouteKey()])
            ->assertActionVisible('startOAuthFlow');
    }

    public function test_connection_resource_start_oauth_flow_hidden_when_access_token_present(): void
    {
        $staff = $this->staffUserWithPermission();
        $this->actingAs($staff);

        $account = $this->makeAccount();
        $active = Connection::factory()->forMollie()->for($account)->create();

        Livewire::test(ViewConnection::class, ['record' => $active->getRouteKey()])
            ->assertActionHidden('startOAuthFlow');
    }

    public function test_connection_resource_revoke_action_remains_intact_after_mount(): void
    {
        $staff = $this->staffUserWithPermission();
        $this->actingAs($staff);

        $account = $this->makeAccount();
        $mollie = Connection::factory()->forMollie()->for($account)->create();

        Livewire::test(ViewConnection::class, ['record' => $mollie->getRouteKey()])
            ->assertActionVisible('revoke');
    }

    public function test_account_resource_mounts_start_oauth_flow_action_for_staff(): void
    {
        $staff = $this->staffUserWithPermission();
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
        $user->givePermissionTo('manage-consumers');
        $this->actingAs($user);

        $account = $this->makeAccount();

        Livewire::test(ListAccounts::class)
            ->assertTableActionHidden('startOAuthFlow', $account);
    }

    public function test_dispatch_returns_back_with_notification_when_provider_disabled(): void
    {
        $account = $this->makeAccount();

        $this->disableProvider('mollie');

        $response = StartOAuthFlowAction::dispatch($account, 'mollie');

        $this->assertSame(0, Connection::query()->count(), 'Disabled provider mag geen Connection inserten');

        $this->assertSame(302, $response->getStatusCode());
    }

    public function test_oauth_capable_providers_excludes_disabled_providers(): void
    {
        $this->disableProvider('mollie');

        $providers = StartOAuthFlowAction::oauthCapableProviders();

        $this->assertArrayNotHasKey('mollie', $providers);
    }

    public function test_dispatch_does_not_create_orphan_connection_when_authorize_url_throws(): void
    {
        $account = $this->makeAccount();

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

        $this->assertSame(0, Connection::query()->count(), 'Geen orphan pending row als authorize-URL faalt');

        $this->assertSame(302, $response->getStatusCode());
    }
}
