<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\User;
use App\OAuth\Mollie\MollieConnectOAuthFlow;
use App\OAuth\Testing\FakeOAuthFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plan 09-06 Task 4 — Revoke-action wiring + delegation.
 *
 * Bewijst (Phase 4-contract + D-04):
 *  - Revoke-action zichtbaar op Mollie-Connection (oauthFlowKey='mollie')
 *  - Revoke-action verborgen op Snelstart-Connection (oauthFlowKey=null)
 *  - Revoke-action verborgen op reeds-revoked Mollie-Connection
 *  - Revoke-action delegates naar OAuthFlow::revoke($connection)
 */
class ConnectionRevokeActionTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
    }

    private function makeStaffUser(): User
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole('staff');

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

        Livewire::test(ListConnections::class)
            ->assertTableActionVisible('revoke', $mollie);
    }

    public function test_revoke_action_hidden_for_snelstart_connection(): void
    {
        $this->actingAs($this->makeStaffUser());
        $snelstart = $this->makeSnelstartConnection();

        Livewire::test(ListConnections::class)
            ->assertTableActionHidden('revoke', $snelstart);
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

        Livewire::test(ListConnections::class)
            ->assertTableActionHidden('revoke', $revoked);
    }

    public function test_revoke_action_calls_oauth_flow_revoke(): void
    {
        // Swap de Mollie-OAuthFlow voor een spy zodat we delegation kunnen bewijzen
        // zonder echte Mollie-API te raken. OAuthFlowRegistry::for('mollie') resolved
        // via container->make($this->providers['mollie']) — een bind volstaat.
        $fake = new FakeOAuthFlow;
        $this->app->instance(MollieConnectOAuthFlow::class, $fake);

        $this->actingAs($this->makeStaffUser());
        $mollie = $this->makeMollieConnection();

        Livewire::test(ListConnections::class)
            ->callTableAction('revoke', $mollie)
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, $fake->wasCalled('revoke'));

        // FakeOAuthFlow::revoke() zet status='revoked' + revoked_at — bewijst
        // dat de Connection daadwerkelijk doorgegeven is.
        $mollie->refresh();
        $this->assertSame('revoked', $mollie->status);
        $this->assertNotNull($mollie->revoked_at);
    }
}
