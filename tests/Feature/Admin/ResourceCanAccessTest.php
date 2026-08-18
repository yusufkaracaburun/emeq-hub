<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ResourceCanAccessTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_inbound_webhook_events_returns_403_for_staff_without_permission(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)->get('/admin/inbound-webhook-events')->assertForbidden();
    }

    public function test_inbound_webhook_events_returns_200_for_staff_with_permission(): void
    {
        $user = $this->staffUser();
        $user->givePermissionTo('view-webhooks');

        $this->actingAs($user)->get('/admin/inbound-webhook-events')->assertOk();
    }

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
