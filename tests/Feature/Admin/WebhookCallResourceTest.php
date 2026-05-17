<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\WebhookCalls\Pages\ListWebhookCalls;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\WebhookClient\Models\WebhookCall;
use Tests\TestCase;

/**
 * Plan 09-07 Task 3 — feature-tests voor WebhookCallResource read-only viewer.
 *
 * Bewijst:
 *  - Staff-User ziet audit-rijen die via 09-01 audit-kolommen geschreven zijn
 *  - Direction-filter narrowt naar incoming
 *  - View-page op `/admin/webhook-calls/{id}` rendert payload-JSON met de key uit het record
 *
 * HUB-04 SC-7 closure (v0.2):
 *  Plan 10-06 (D-3) sluit SC-7 als **permission-gated**, NIET als consumer-scoped.
 *  Staff zonder `view-webhooks` krijgt 403; staff mét de permission ziet ALLE
 *  consumer-webhooks. Per-Consumer staff-binding is v1.0+ scope.
 */
class WebhookCallResourceTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'view-webhooks']);
    }

    private function actAsStaff(): User
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('view-webhooks');
        $this->actingAs($user);

        return $user;
    }

    private function insertWebhookCall(array $overrides = []): int
    {
        $base = [
            'name' => 'mollie.payment.test',
            'url' => 'https://hub.emeq.test/webhooks/mollie',
            'headers' => json_encode([]),
            'payload' => json_encode(['order_id' => 'ord_DEFAULT']),
            'direction' => 'incoming',
            'provider' => 'mollie',
            'consumer_id' => null,
            'status' => 'processed',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('webhook_calls')->insertGetId(array_merge($base, $overrides));
    }

    public function test_list_shows_audit_rows_for_staff_user(): void
    {
        $this->actAsStaff();
        $consumer = Consumer::factory()->create();

        $this->insertWebhookCall([
            'direction' => 'incoming',
            'provider' => 'mollie',
            'consumer_id' => $consumer->id,
            'status' => 'processed',
        ]);
        $this->insertWebhookCall([
            'direction' => 'outgoing',
            'provider' => 'snelstart',
            'consumer_id' => $consumer->id,
            'status' => 'failed',
        ]);

        Livewire::test(ListWebhookCalls::class)
            ->assertCanSeeTableRecords(WebhookCall::all())
            ->assertCountTableRecords(2);
    }

    public function test_direction_filter_narrows_to_incoming(): void
    {
        $this->actAsStaff();

        $this->insertWebhookCall(['direction' => 'incoming', 'provider' => 'mollie']);
        $this->insertWebhookCall(['direction' => 'outgoing', 'provider' => 'cashier']);

        Livewire::test(ListWebhookCalls::class)
            ->filterTable('direction', 'incoming')
            ->assertCanSeeTableRecords(WebhookCall::where('direction', 'incoming')->get())
            ->assertCountTableRecords(1);
    }

    public function test_view_page_renders_payload_json(): void
    {
        $this->actAsStaff();

        $id = $this->insertWebhookCall([
            'payload' => json_encode(['order_id' => 'ord_TEST_123']),
        ]);

        $response = $this->get("/admin/webhook-calls/{$id}");

        $response->assertOk();
        $response->assertSee('ord_TEST_123');
    }

    public function test_list_shows_consumer_slug_via_relation(): void
    {
        $this->actAsStaff();
        $consumer = Consumer::factory()->create(['slug' => 'test-slug-xyz']);

        $this->insertWebhookCall([
            'direction' => 'incoming',
            'provider' => 'mollie',
            'consumer_id' => $consumer->id,
            'status' => 'processed',
        ]);

        Livewire::test(ListWebhookCalls::class)
            ->assertCanSeeTableRecords(WebhookCall::all())
            ->assertSee('test-slug-xyz');
    }

    public function test_view_page_renders_exception_as_plain_text_not_json_encoded(): void
    {
        $this->actAsStaff();

        // Spatie's saveException() schrijft exception als JSON-encoded array
        // (`code` / `message` / `trace`) — match dat patroon zodat de array-cast
        // op de Spatie-parent class correct decodet.
        $exceptionPayload = [
            'code' => 0,
            'message' => 'Stack trace line 1',
            'trace' => "Stack trace line 1\nStack trace line 2",
        ];

        $id = $this->insertWebhookCall([
            'exception' => json_encode($exceptionPayload),
        ]);

        $response = $this->get("/admin/webhook-calls/{$id}");

        $response->assertOk();
        $response->assertSee('Stack trace line 1');
        // Het oude dubbel-encoded patroon (Filament rendert `json_encode($array)`
        // output → escaped quotes en `\n` letterlijk) mag niet meer voorkomen.
        $response->assertDontSee('Stack trace line 1\\nStack trace line 2', false);
    }

    public function test_view_page_shows_consumer_slug_via_relation(): void
    {
        $this->actAsStaff();
        $consumer = Consumer::factory()->create(['slug' => 'test-slug-xyz']);

        $id = $this->insertWebhookCall([
            'consumer_id' => $consumer->id,
        ]);

        $response = $this->get("/admin/webhook-calls/{$id}");

        $response->assertOk();
        $response->assertSee('test-slug-xyz');
    }

    /**
     * HUB-04 SC-7 closure — deel 1 (D-3 v0.2-keuze):
     * Staff zonder `view-webhooks` permission krijgt 403 op /admin/webhook-calls.
     * Permission-gating is de v0.2-invulling van "cross-Consumer-isolation" zoals
     * gedocumenteerd in 10-CONTEXT.md D-3.
     */
    public function test_staff_without_view_webhooks_permission_cannot_access_webhooks_resource(): void
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole('staff');
        // Bewust GEEN givePermissionTo('view-webhooks').
        $this->actingAs($user);

        $this->get('/admin/webhook-calls')->assertForbidden();
    }

    /**
     * HUB-04 SC-7 closure — deel 2 (D-3 v0.2-keuze):
     * Staff MET `view-webhooks` permission ziet ALLE consumer-webhooks. Dit is
     * een bewuste v0.2-keuze: cross-Consumer-zichtbaarheid voor staff is acceptabel
     * zolang permission-gating de access-control is. Een latere fase (v1.0+,
     * externe staff per Consumer) introduceert staff↔consumer-binding waarna
     * cross-Consumer-isolation een filter-niveau op de query wordt.
     *
     * @see .planning/phases/10-phase-9-polish-deferred-review-findings/10-CONTEXT.md D-3
     */
    public function test_cross_consumer_isolation_staff_with_view_webhooks_permission_sees_all_webhooks_per_v02_decision_d3(): void
    {
        $this->actAsStaff();

        $consumerA = Consumer::factory()->create(['slug' => 'consumer-a']);
        $consumerB = Consumer::factory()->create(['slug' => 'consumer-b']);

        $this->insertWebhookCall([
            'consumer_id' => $consumerA->id,
            'provider' => 'mollie',
        ]);
        $this->insertWebhookCall([
            'consumer_id' => $consumerB->id,
            'provider' => 'snelstart',
        ]);

        // Staff mét view-webhooks ziet beide consumer-webhooks — bewuste v0.2-keuze.
        Livewire::test(ListWebhookCalls::class)
            ->assertCountTableRecords(2)
            ->assertSee('consumer-a')
            ->assertSee('consumer-b');
    }
}
