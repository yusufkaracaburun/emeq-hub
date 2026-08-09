<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\DemoRequests\Pages\ListDemoRequests;
use App\Models\DemoRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Spiegelt AccessRequestDeclineActionTest: ook een demo-aanvraag die we niet
 * oppakken moet 'declined' kunnen worden in plaats van 'handled'.
 */
class DemoRequestDeclineActionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStaff(): User
    {
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-consumers']);

        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('manage-consumers');
        $this->actingAs($user);

        return $user;
    }

    public function test_decline_action_sets_status_to_declined(): void
    {
        $this->actingAsStaff();

        $request = DemoRequest::factory()->create(['status' => 'new']);

        Livewire::test(ListDemoRequests::class)
            ->callTableAction('decline', $request)
            ->assertHasNoTableActionErrors();

        $this->assertSame('declined', $request->fresh()->status);
    }

    public function test_decline_action_visible_on_new_requests_only(): void
    {
        $this->actingAsStaff();

        $new = DemoRequest::factory()->create(['status' => 'new']);
        $handled = DemoRequest::factory()->create(['status' => 'handled']);
        $declined = DemoRequest::factory()->create(['status' => 'declined']);

        $component = Livewire::test(ListDemoRequests::class);

        $component->assertTableActionVisible('decline', $new);
        $component->assertTableActionHidden('decline', $handled);
        $component->assertTableActionHidden('decline', $declined);
    }

    public function test_declined_request_can_no_longer_be_handled(): void
    {
        $this->actingAsStaff();

        $request = DemoRequest::factory()->create(['status' => 'new']);

        Livewire::test(ListDemoRequests::class)
            ->callTableAction('decline', $request)
            ->assertTableActionHidden('handle', $request->fresh());
    }
}
