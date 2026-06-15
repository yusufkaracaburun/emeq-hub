<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\PassThroughCalls\Pages\ListPassThroughCalls;
use App\Models\Consumer;
use App\Models\PassThroughCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Read-only viewer voor de immutable `pass_through_calls`-audit.
 *
 * Spiegelt WebhookCallResourceTest. Permission-gating volgt dezelfde v0.2-keuze
 * (D-3): staff zonder `view-pass-through-calls` krijgt 403; staff mét de permission
 * ziet ALLE consumer-calls (cross-Consumer-binding is v1.0+ scope).
 */
class PassThroughCallResourceTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'view-pass-through-calls']);
    }

    private function actAsStaff(): User
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('view-pass-through-calls');
        $this->actingAs($user);

        return $user;
    }

    public function test_list_shows_audit_rows_for_staff_user(): void
    {
        $this->actAsStaff();

        PassThroughCall::factory()->create();
        PassThroughCall::factory()->inbound()->create();

        Livewire::test(ListPassThroughCalls::class)
            ->assertCanSeeTableRecords(PassThroughCall::all())
            ->assertCountTableRecords(2);
    }

    public function test_direction_filter_narrows_to_inbound(): void
    {
        $this->actAsStaff();

        PassThroughCall::factory()->create();               // outbound (default)
        PassThroughCall::factory()->inbound()->create();    // inbound

        Livewire::test(ListPassThroughCalls::class)
            ->filterTable('direction', 'inbound')
            ->assertCanSeeTableRecords(PassThroughCall::where('direction', 'inbound')->get())
            ->assertCountTableRecords(1);
    }

    public function test_status_class_filter_narrows_to_server_error(): void
    {
        $this->actAsStaff();

        PassThroughCall::factory()->create(['status' => 200]);
        PassThroughCall::factory()->create(['status' => 503]);

        Livewire::test(ListPassThroughCalls::class)
            ->filterTable('status_class', 'server_error')
            ->assertCanSeeTableRecords(PassThroughCall::where('status', '>=', 500)->get())
            ->assertCountTableRecords(1);
    }

    public function test_view_page_renders_diagnostics(): void
    {
        $this->actAsStaff();

        $call = PassThroughCall::factory()->create([
            'path' => 'relaties/v2/UNIQUE_PATH_XYZ',
            'request_fingerprint' => 'abc123def456',
            'upstream_error' => 'snelstart_auth',
        ]);

        $response = $this->get("/admin/pass-through-calls/{$call->id}");

        $response->assertOk();
        $response->assertSee('relaties/v2/UNIQUE_PATH_XYZ');
        $response->assertSee('abc123def456');
        $response->assertSee('snelstart_auth');
    }

    public function test_view_page_shows_consumer_slug_via_relation(): void
    {
        $this->actAsStaff();
        $consumer = Consumer::factory()->create(['slug' => 'test-slug-xyz']);

        $call = PassThroughCall::factory()->create(['consumer_id' => $consumer->id]);

        $response = $this->get("/admin/pass-through-calls/{$call->id}");

        $response->assertOk();
        $response->assertSee('test-slug-xyz');
    }

    public function test_staff_without_permission_cannot_access_resource(): void
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole('staff');
        // Bewust GEEN givePermissionTo('view-pass-through-calls').
        $this->actingAs($user);

        $this->get('/admin/pass-through-calls')->assertForbidden();
    }

    public function test_cross_consumer_visible_for_staff_with_permission_per_v02_decision_d3(): void
    {
        $this->actAsStaff();

        $consumerA = Consumer::factory()->create(['slug' => 'consumer-a']);
        $consumerB = Consumer::factory()->create(['slug' => 'consumer-b']);

        PassThroughCall::factory()->create(['consumer_id' => $consumerA->id]);
        PassThroughCall::factory()->create(['consumer_id' => $consumerB->id]);

        Livewire::test(ListPassThroughCalls::class)
            ->assertCountTableRecords(2)
            ->assertSee('consumer-a')
            ->assertSee('consumer-b');
    }
}
