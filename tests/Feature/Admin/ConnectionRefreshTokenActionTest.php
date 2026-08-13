<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Connections\Pages\ViewConnection;
use App\Integrations\Contracts\OAuthFlow;
use App\Integrations\Exact\OAuth\ExactOAuthFlow;
use App\Integrations\OAuth\Testing\FakeOAuthFlow;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Handmatige token-refresh vanaf de Connection-detailpagina: zichtbaarheid,
 * delegation naar de OAuthFlow, en de eerlijke melding wanneer de provider de
 * refresh weigert omdat de access-token nog geldig is.
 */
class ConnectionRefreshTokenActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeStaffUser(): User
    {
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-connections']);
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('manage-connections');

        return $user;
    }

    private function makeConnection(string $state, array $attributes = []): Connection
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        return Connection::factory()->{$state}()->for($account)->create($attributes);
    }

    private function page(Connection $connection): Testable
    {
        return Livewire::test(ViewConnection::class, ['record' => $connection->getKey()]);
    }

    public function test_action_visible_for_exact_connection_with_refresh_token(): void
    {
        $this->actingAs($this->makeStaffUser());

        $this->page($this->makeConnection('forExact'))
            ->assertActionVisible('refreshToken');
    }

    public function test_action_hidden_for_provider_without_oauth_flow(): void
    {
        $this->actingAs($this->makeStaffUser());

        $this->page($this->makeConnection('forSnelstart'))
            ->assertActionHidden('refreshToken');
    }

    public function test_action_hidden_without_refresh_token(): void
    {
        $this->actingAs($this->makeStaffUser());

        $this->page($this->makeConnection('forExact', ['refresh_token' => null]))
            ->assertActionHidden('refreshToken');
    }

    public function test_action_hidden_for_revoked_connection(): void
    {
        $this->actingAs($this->makeStaffUser());

        $this->page($this->makeConnection('forExact', ['revoked_at' => now(), 'status' => 'revoked']))
            ->assertActionHidden('refreshToken');
    }

    public function test_action_delegates_to_the_oauth_flow_and_reports_the_new_expiry(): void
    {
        $fake = new FakeOAuthFlow;
        $this->app->instance(ExactOAuthFlow::class, $fake);

        $this->actingAs($this->makeStaffUser());
        $exact = $this->makeConnection('forExact', ['expires_at' => now()->subMinute()]);

        $this->page($exact)
            ->callAction('refreshToken')
            ->assertHasNoActionErrors()
            ->assertNotified('Token ververst');

        $this->assertSame(1, $fake->wasCalled('refreshToken'));
        $this->assertTrue($exact->refresh()->expires_at->isFuture());
    }

    public function test_action_reports_honestly_when_the_provider_refuses_a_premature_refresh(): void
    {
        // Exact weigert een refresh zolang de access-token nog geldig is; de flow
        // slaat de token-call dan over. De UI moet dat melden, niet "ververst" liegen.
        Http::fake();

        $this->actingAs($this->makeStaffUser());
        $exact = $this->makeConnection('forExact', ['expires_at' => now()->addMinutes(30)]);
        $before = $exact->expires_at;

        $this->page($exact)
            ->callAction('refreshToken')
            ->assertHasNoActionErrors()
            ->assertNotified(
                Notification::make()
                    ->title('Token nog geldig — niets ververst')
                    ->body('De provider staat een refresh pas rond de expiry toe. Geldig tot '.$before->format('d-m-Y H:i').'.')
                    ->warning()
            );

        Http::assertNothingSent();
        $this->assertTrue($exact->refresh()->expires_at->equalTo($before));
    }

    public function test_action_shows_a_fingerprinted_failure_notification_on_throwable(): void
    {
        // ExactOAuthFlow is final — een anonieme OAuthFlow die gooit, gebonden op de
        // key die OAuthFlowRegistry resolvet, doet hetzelfde werk als een mock.
        $this->app->instance(ExactOAuthFlow::class, new class implements OAuthFlow
        {
            public function getAuthorizationUrl(Account $account, array $scopes, string $state): string
            {
                return 'https://fake.oauth.local/authorize';
            }

            public function exchangeCode(Connection $connection, string $code): Connection
            {
                return $connection;
            }

            public function refreshToken(Connection $connection): Connection
            {
                throw new \RuntimeException('exact-test-refresh-error');
            }

            public function revoke(Connection $connection): void {}
        });

        $this->actingAs($this->makeStaffUser());
        $exact = $this->makeConnection('forExact', ['expires_at' => now()->subMinute()]);

        $this->page($exact)
            ->callAction('refreshToken')
            ->assertNotified(
                Notification::make()
                    ->title('Token verversen mislukt')
                    ->body('Zie logs voor details — fingerprint: '.substr(hash('sha256', 'exact-test-refresh-error'), 0, 12))
                    ->danger()
            );
    }
}
