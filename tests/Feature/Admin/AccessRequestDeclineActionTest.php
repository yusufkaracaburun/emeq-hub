<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\AccessRequests\Pages\ListAccessRequests;
use App\Models\AccessRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * De afwijs-tak van de koppel-intake: een lead die we niet onboarden krijgt
 * status 'declined' in plaats van 'handled', zodat de inbox-filter klopt en
 * de onboard-actie er niet meer op kan.
 */
class AccessRequestDeclineActionTest extends TestCase
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

        $request = AccessRequest::factory()->create(['status' => 'new']);

        Livewire::test(ListAccessRequests::class)
            ->callTableAction('decline', $request)
            ->assertHasNoTableActionErrors();

        $this->assertSame('declined', $request->fresh()->status);
    }

    public function test_decline_action_visible_on_new_requests_only(): void
    {
        $this->actingAsStaff();

        $new = AccessRequest::factory()->create(['status' => 'new']);
        $handled = AccessRequest::factory()->create(['status' => 'handled']);
        $declined = AccessRequest::factory()->create(['status' => 'declined']);

        $component = Livewire::test(ListAccessRequests::class);

        $component->assertTableActionVisible('decline', $new);
        $component->assertTableActionHidden('decline', $handled);
        $component->assertTableActionHidden('decline', $declined);
    }

    public function test_declined_request_can_no_longer_be_onboarded_or_handled(): void
    {
        $this->actingAsStaff();

        $request = AccessRequest::factory()->create(['status' => 'new']);

        $component = Livewire::test(ListAccessRequests::class)
            ->callTableAction('decline', $request);

        $component->assertTableActionHidden('onboard', $request->fresh());
        $component->assertTableActionHidden('handle', $request->fresh());
    }

    public function test_decline_does_not_link_a_consumer(): void
    {
        $this->actingAsStaff();

        $request = AccessRequest::factory()->create(['status' => 'new', 'consumer_id' => null]);

        Livewire::test(ListAccessRequests::class)
            ->callTableAction('decline', $request)
            ->assertHasNoTableActionErrors();

        $this->assertNull($request->fresh()->consumer_id);
    }
}
