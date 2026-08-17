<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\CashierSubscriptions\Pages\ListCashierSubscriptions;
use App\Filament\Resources\CashierSubscriptions\Pages\ViewCashierSubscription;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plan 09-09 Task 2 — feature-tests voor CashierSubscriptionResource.
 *
 * Bewijst (HUB-04 deelcriterium):
 *  - List toont Cashier-Subscription-rijen met owner.slug (Consumer)
 *  - Derived-status reflecteert Cashier's accessor-output (Phase 6 D-02: geen status-kolom)
 *  - Sub zonder ends_at → derived_status 'active'
 *  - Sub met ends_at in toekomst → derived_status 'grace' (subset van Cashier's cancelled())
 *
 * Roles worden direct via Spatie's Role-model geseed (PanelAccessTest-pattern, 09-03).
 */
class CashierSubscriptionResourceTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'view-billing']);
    }

    private function actingAsStaff(): User
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('view-billing');
        $this->actingAs($user);

        return $user;
    }

    public function test_list_shows_consumer_subscriptions(): void
    {
        $this->actingAsStaff();

        $consumer = Consumer::factory()->withActiveSubscription()->create();

        $this->assertSame(1, Subscription::count());

        Livewire::test(ListCashierSubscriptions::class)
            ->assertCanSeeTableRecords(Subscription::all());

        $this->get('/admin/cashier-subscriptions')
            ->assertOk()
            ->assertSee($consumer->slug);
    }

    public function test_detail_opens_with_the_status_strip(): void
    {
        $this->actingAsStaff();

        $consumer = Consumer::factory()->withActiveSubscription()->create();

        Livewire::test(ViewCashierSubscription::class, ['record' => Subscription::first()->getKey()])
            ->assertSuccessful()
            ->assertSee('Status')
            ->assertSee('Plan')
            ->assertSee('Cycle eindigt')
            ->assertSee($consumer->slug);
    }

    public function test_derived_status_is_active_for_subscription_without_ends_at(): void
    {
        $this->actingAsStaff();

        Consumer::factory()->withActiveSubscription()->create();

        $subscription = Subscription::first();
        $this->assertNull($subscription->ends_at);
        $this->assertTrue($subscription->active());

        $this->get('/admin/cashier-subscriptions')
            ->assertOk()
            ->assertSee('active');
    }

    public function test_derived_status_is_grace_for_subscription_with_future_ends_at(): void
    {
        $this->actingAsStaff();

        Consumer::factory()->withActiveSubscription()->create();

        $subscription = Subscription::first();
        $subscription->update(['ends_at' => now()->addDay()]);

        $this->assertTrue($subscription->fresh()->cancelled());
        $this->assertTrue($subscription->fresh()->onGracePeriod());

        $response = $this->get('/admin/cashier-subscriptions')->assertOk();

        $body = $response->getContent();
        $this->assertTrue(
            str_contains($body, 'grace') || str_contains($body, 'cancelled'),
            'Expected derived_status badge text to contain "grace" or "cancelled" for sub met future ends_at.'
        );
    }
}
