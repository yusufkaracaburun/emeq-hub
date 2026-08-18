<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Connections\Pages\ViewConnection;
use App\Integrations\Mollie\OAuth\MollieConnectOAuthFlow;
use App\Integrations\OAuth\Testing\FakeOAuthFlow;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConnectionRevokeActionTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-connections']);
    }

    private function makeStaffUser(): User
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('manage-connections');

        return $user;
    }

    private function makeMollieConnection(): Connection
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        return Connection::factory()->forMollie()->for($account)->create();
    }

    private function makeSnelstartConnection(): Connection
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        return Connection::factory()->forSnelstart()->for($account)->create();
    }

    public function test_revoke_action_visible_for_mollie_connection(): void
    {
        $this->actingAs($this->makeStaffUser());
        $mollie = $this->makeMollieConnection();

        Livewire::test(ViewConnection::class, ['record' => $mollie->getRouteKey()])
            ->assertActionVisible('revoke');
    }

    public function test_revoke_action_hidden_for_snelstart_connection(): void
    {
        $this->actingAs($this->makeStaffUser());
        $snelstart = $this->makeSnelstartConnection();

        Livewire::test(ViewConnection::class, ['record' => $snelstart->getRouteKey()])
            ->assertActionHidden('revoke');
    }

    public function test_revoke_action_hidden_for_already_revoked_connection(): void
    {
        $this->actingAs($this->makeStaffUser());
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $revoked = Connection::factory()->forMollie()->for($account)->create([
            'revoked_at' => now(),
            'status' => 'revoked',
        ]);

        Livewire::test(ViewConnection::class, ['record' => $revoked->getRouteKey()])
            ->assertActionHidden('revoke');
    }

    public function test_revoke_action_calls_oauth_flow_revoke(): void
    {
        $fake = new FakeOAuthFlow;
        $this->app->instance(MollieConnectOAuthFlow::class, $fake);

        $this->actingAs($this->makeStaffUser());
        $mollie = $this->makeMollieConnection();

        Livewire::test(ViewConnection::class, ['record' => $mollie->getRouteKey()])
            ->callAction('revoke')
            ->assertHasNoActionErrors();

        $this->assertSame(1, $fake->wasCalled('revoke'));

        $mollie->refresh();
        $this->assertSame('revoked', $mollie->status);
        $this->assertNotNull($mollie->revoked_at);
    }
}
