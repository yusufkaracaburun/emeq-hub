<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plan 10-03 D-1 — bewijst dat alle 6 niet-User-Filament-resources
 * permission-gated zijn via Spatie's `can()`-check. Sluit CR-02 BLOCKER
 * uit 09-REVIEW.md: staff zonder de gemapte permission ziet de resource
 * niet (canAccess=false) én krijgt 403 op de directe `/admin/<resource>`-URL.
 *
 * Permission-mapping (locked per 10-CONTEXT.md D-1):
 *  - consumers / accounts       → manage-consumers
 *  - connections                → manage-connections
 *  - webhook-calls              → view-webhooks
 *  - account-subscriptions      → view-account-subscriptions
 *  - cashier-subscriptions      → view-billing
 *
 * UserResource (gated via `manage-staff`-Gate, niet permission-can()) wordt
 * door PermissionGatingTest gecoverd — niet hier.
 */
class ResourceCanAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed alleen `staff`-rol + de 5 permissions die door D-1 worden gemapt.
     * Geen `super-admin`-rol — die zou alle permissions overrulen via Spatie's
     * Gate::before-hook en de gating-bewijslast verstoren.
     */
    private function seedRolesAndPermissions(): void
    {
        Role::firstOrCreate(['name' => 'staff']);

        foreach (
            [
                'manage-consumers',
                'manage-connections',
                'view-webhooks',
                'view-account-subscriptions',
                'view-billing',
            ] as $permission
        ) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }

    private function staffUser(): User
    {
        $this->seedRolesAndPermissions();
        $user = User::factory()->create();
        $user->assignRole('staff');

        return $user;
    }

    // ---------------- ConsumerResource (manage-consumers) ----------------

    public function test_consumers_returns_403_for_staff_without_permission(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)->get('/admin/consumers')->assertForbidden();
    }

    public function test_consumers_returns_200_for_staff_with_permission(): void
    {
        $user = $this->staffUser();
        $user->givePermissionTo('manage-consumers');

        $this->actingAs($user)->get('/admin/consumers')->assertOk();
    }

    // ---------------- ConnectionResource (manage-connections) ----------------

    public function test_connections_returns_403_for_staff_without_permission(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)->get('/admin/connections')->assertForbidden();
    }

    public function test_connections_returns_200_for_staff_with_permission(): void
    {
        $user = $this->staffUser();
        $user->givePermissionTo('manage-connections');

        $this->actingAs($user)->get('/admin/connections')->assertOk();
    }

    // ---------------- AccountResource (manage-consumers — D-1) ----------------

    public function test_accounts_returns_403_for_staff_without_permission(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)->get('/admin/accounts')->assertForbidden();
    }

    public function test_accounts_returns_200_for_staff_with_permission(): void
    {
        $user = $this->staffUser();
        $user->givePermissionTo('manage-consumers');

        $this->actingAs($user)->get('/admin/accounts')->assertOk();
    }

    // ---------------- WebhookCallResource (view-webhooks) ----------------

    public function test_webhook_calls_returns_403_for_staff_without_permission(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)->get('/admin/webhook-calls')->assertForbidden();
    }

    public function test_webhook_calls_returns_200_for_staff_with_permission(): void
    {
        $user = $this->staffUser();
        $user->givePermissionTo('view-webhooks');

        $this->actingAs($user)->get('/admin/webhook-calls')->assertOk();
    }

    // ---------------- AccountSubscriptionResource (view-account-subscriptions) ----------------

    public function test_account_subscriptions_returns_403_for_staff_without_permission(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)->get('/admin/account-subscriptions')->assertForbidden();
    }

    public function test_account_subscriptions_returns_200_for_staff_with_permission(): void
    {
        $user = $this->staffUser();
        $user->givePermissionTo('view-account-subscriptions');

        $this->actingAs($user)->get('/admin/account-subscriptions')->assertOk();
    }

    // ---------------- CashierSubscriptionResource (view-billing) ----------------

    public function test_cashier_subscriptions_returns_403_for_staff_without_permission(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)->get('/admin/cashier-subscriptions')->assertForbidden();
    }

    public function test_cashier_subscriptions_returns_200_for_staff_with_permission(): void
    {
        $user = $this->staffUser();
        $user->givePermissionTo('view-billing');

        $this->actingAs($user)->get('/admin/cashier-subscriptions')->assertOk();
    }
}
