<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\InboundWebhookEvents\Pages\ListInboundWebhookEvents;
use App\Models\Account;
use App\Models\Consumer;
use App\Models\InboundWebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature-tests voor de read-only InboundWebhookEventResource (Partner → Hub
 * audit-viewer). Mirror van de oude WebhookCallResourceTest na de migratie
 * `webhook_calls` (Spatie) → `inbound_webhook_events` (metadata-only).
 *
 * Bewijst:
 *  - Staff-User ziet audit-rijen die door InboundWebhookRecorder geschreven zijn
 *  - Outcome-filter narrowt de tabel
 *  - View-page op `/admin/inbound-webhook-events/{id}` rendert event-metadata
 *  - Consumer-slug komt via de relatie de tabel/view in
 *  - Permission-gating (view-webhooks): zonder permission 403
 */
class InboundWebhookEventResourceTest extends TestCase
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

    private function eventForConsumer(Consumer $consumer, array $overrides = []): InboundWebhookEvent
    {
        $account = Account::factory()->for($consumer)->create();

        return InboundWebhookEvent::factory()->create(array_merge([
            'consumer_id' => $consumer->id,
            'account_id' => $account->id,
        ], $overrides));
    }

    public function test_list_shows_audit_rows_for_staff_user(): void
    {
        $this->actAsStaff();
        $consumer = Consumer::factory()->create();

        $this->eventForConsumer($consumer, ['provider' => 'mollie', 'outcome' => 'processed']);
        $this->eventForConsumer($consumer, ['provider' => 'snelstart', 'outcome' => 'unknown_tenant']);

        Livewire::test(ListInboundWebhookEvents::class)
            ->assertCanSeeTableRecords(InboundWebhookEvent::all())
            ->assertCountTableRecords(2);
    }

    public function test_outcome_filter_narrows_table(): void
    {
        $this->actAsStaff();
        $consumer = Consumer::factory()->create();

        $this->eventForConsumer($consumer, ['provider' => 'mollie', 'outcome' => 'processed']);
        $this->eventForConsumer($consumer, ['provider' => 'cashier', 'outcome' => 'misconfigured']);

        Livewire::test(ListInboundWebhookEvents::class)
            ->filterTable('outcome', 'processed')
            ->assertCanSeeTableRecords(InboundWebhookEvent::where('outcome', 'processed')->get())
            ->assertCountTableRecords(1);
    }

    public function test_view_page_renders_event_metadata(): void
    {
        $this->actAsStaff();
        $consumer = Consumer::factory()->create();

        $event = $this->eventForConsumer($consumer, [
            'event_id' => 'evt_TEST_123',
        ]);

        $response = $this->get("/admin/inbound-webhook-events/{$event->id}");

        $response->assertOk();
        $response->assertSee('evt_TEST_123');
    }

    public function test_list_shows_consumer_slug_via_relation(): void
    {
        $this->actAsStaff();
        $consumer = Consumer::factory()->create(['slug' => 'test-slug-xyz']);

        $this->eventForConsumer($consumer, ['provider' => 'mollie', 'outcome' => 'processed']);

        Livewire::test(ListInboundWebhookEvents::class)
            ->assertCanSeeTableRecords(InboundWebhookEvent::all())
            ->assertSee('test-slug-xyz');
    }

    public function test_view_page_shows_consumer_slug_via_relation(): void
    {
        $this->actAsStaff();
        $consumer = Consumer::factory()->create(['slug' => 'test-slug-xyz']);

        $event = $this->eventForConsumer($consumer);

        $response = $this->get("/admin/inbound-webhook-events/{$event->id}");

        $response->assertOk();
        $response->assertSee('test-slug-xyz');
    }

    public function test_staff_without_view_webhooks_permission_cannot_access_resource(): void
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole('staff');
        // Bewust GEEN givePermissionTo('view-webhooks').
        $this->actingAs($user);

        $this->get('/admin/inbound-webhook-events')->assertForbidden();
    }

    public function test_staff_with_view_webhooks_permission_sees_all_consumer_events(): void
    {
        $this->actAsStaff();

        $consumerA = Consumer::factory()->create(['slug' => 'consumer-a']);
        $consumerB = Consumer::factory()->create(['slug' => 'consumer-b']);

        $this->eventForConsumer($consumerA, ['provider' => 'mollie']);
        $this->eventForConsumer($consumerB, ['provider' => 'snelstart']);

        Livewire::test(ListInboundWebhookEvents::class)
            ->assertCountTableRecords(2)
            ->assertSee('consumer-a')
            ->assertSee('consumer-b');
    }
}
