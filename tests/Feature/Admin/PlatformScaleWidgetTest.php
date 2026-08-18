<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Widgets\PlatformScaleWidget;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformScaleWidgetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, int> */
    private function counts(): array
    {
        return (new PlatformScaleWidget)->platformCounts();
    }

    public function test_empty_platform_reports_all_zero(): void
    {
        $this->assertSame(
            [
                'consumers' => 0,
                'connected_consumers' => 0,
                'accounts' => 0,
                'connected_accounts' => 0,
                'connections' => 0,
                'active_connections' => 0,
                'revoked_connections' => 0,
            ],
            $this->counts(),
        );
    }

    public function test_counts_every_link_in_the_chain(): void
    {
        $consumer = Consumer::factory()->create();
        $accounts = Account::factory()->count(3)->create(['consumer_id' => $consumer->id]);
        Connection::factory()->create(['account_id' => $accounts->first()->id, 'provider' => 'exact']);
        Connection::factory()->create(['account_id' => $accounts->first()->id, 'provider' => 'snelstart']);

        $counts = $this->counts();

        $this->assertSame(1, $counts['consumers']);
        $this->assertSame(3, $counts['accounts']);
        $this->assertSame(2, $counts['connections']);
    }

    public function test_connected_tallies_ignore_revoked_and_pending_connections(): void
    {
        $connected = Account::factory()->create();
        Connection::factory()->create(['account_id' => $connected->id, 'status' => 'active', 'revoked_at' => null]);

        $revokedOnly = Account::factory()->create();
        Connection::factory()->create(['account_id' => $revokedOnly->id, 'status' => 'active', 'revoked_at' => now()]);

        $pendingOnly = Account::factory()->create();
        Connection::factory()->create(['account_id' => $pendingOnly->id, 'status' => 'pending', 'revoked_at' => null]);

        Account::factory()->create();

        $counts = $this->counts();

        $this->assertSame(4, $counts['accounts']);
        $this->assertSame(1, $counts['connected_accounts']);
        $this->assertSame(4, $counts['consumers']);
        $this->assertSame(1, $counts['connected_consumers']);
        $this->assertSame(3, $counts['connections']);
        $this->assertSame(1, $counts['active_connections']);
        $this->assertSame(1, $counts['revoked_connections']);
    }

    public function test_widget_renders_the_totals(): void
    {
        $this->actAsStaff();

        $consumer = Consumer::factory()->create();
        $accounts = Account::factory()->count(2)->create(['consumer_id' => $consumer->id]);
        Connection::factory()->create(['account_id' => $accounts->first()->id, 'status' => 'active', 'revoked_at' => null]);

        Livewire::test(PlatformScaleWidget::class)
            ->assertSee('Consumers')
            ->assertSee('Accounts')
            ->assertSee('Connections')
            ->assertSee('1 met een actieve koppeling')
            ->assertSee('1 actief · 0 revoked');
    }

    public function test_boekhouder_cannot_view_the_widget(): void
    {
        Role::firstOrCreate(['name' => 'boekhouder']);
        $user = User::factory()->create();
        $user->assignRole('boekhouder');
        $this->actingAs($user);

        $this->assertFalse(PlatformScaleWidget::canView());
    }

    public function test_staff_can_view_the_widget(): void
    {
        $this->actAsStaff();

        $this->assertTrue(PlatformScaleWidget::canView());
    }

    private function actAsStaff(): User
    {
        Role::firstOrCreate(['name' => 'staff']);
        $user = User::factory()->create();
        $user->assignRole('staff');
        $this->actingAs($user);

        return $user;
    }

    public function test_consumer_counts_once_regardless_of_how_many_accounts_are_connected(): void
    {
        $consumer = Consumer::factory()->create();
        $accounts = Account::factory()->count(2)->create(['consumer_id' => $consumer->id]);

        foreach ($accounts as $account) {
            Connection::factory()->create(['account_id' => $account->id, 'status' => 'active', 'revoked_at' => null]);
        }

        $counts = $this->counts();

        $this->assertSame(1, $counts['connected_consumers']);
        $this->assertSame(2, $counts['connected_accounts']);
    }
}
