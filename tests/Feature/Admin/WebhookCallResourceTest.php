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

        $id = $this->insertWebhookCall([
            'exception' => "Stack trace line 1\nStack trace line 2",
        ]);

        $response = $this->get("/admin/webhook-calls/{$id}");

        $response->assertOk();
        $response->assertSee('Stack trace line 1');
        // Double-encoded JSON form (with escaped quotes + escaped \n) must NOT appear.
        $response->assertDontSee('"Stack trace line 1\\nStack trace line 2"', false);
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
}
