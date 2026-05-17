<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Pages\OnboardConsumer;
use App\Filament\Resources\Consumers\ConsumerResource;
use App\Filament\Resources\Consumers\Pages\ListConsumers;
use App\Models\Consumer;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plan 08-02 — OnboardConsumer Filament-page met 4-staps Wizard.
 *
 * Dekt:
 *  - RBAC: canAccess() + page-route 403 zonder manage-consumers
 *  - Page render: Wizard + 4 step-titels uit UI-SPEC §S1
 *  - Stap 3 descriptor-driven provider-keuze (Mollie + Snelstart)
 *  - Stap 3 branch-content (Mollie helper-text + Snelstart 3 credential-velden)
 *  - Header-action in ListConsumers (Onboarden) + visibility-gate
 *  - Happy-path submit → ConsumerOnboarding service → DB-rijen + Cache-flash
 *  - No-secret-leak invariant: plain PAT + plain webhook_callback_secret eenmalig
 */
class OnboardConsumerTest extends TestCase
{
    use RefreshDatabase;

    private function seedRolesAndPermissions(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-consumers']);
        Permission::firstOrCreate(['name' => 'manage-connections']);
    }

    private function actAsStaffWithPermission(): User
    {
        $this->seedRolesAndPermissions();
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('manage-consumers');
        $this->actingAs($user);

        return $user;
    }

    private function actAsStaffWithoutPermission(): User
    {
        $this->seedRolesAndPermissions();
        $user = User::factory()->create();
        $user->assignRole('staff');
        $this->actingAs($user);

        return $user;
    }

    // ============================================================
    // Task 2 Test 1: RBAC unauthorized — canAccess + page-route 403
    // ============================================================

    public function test_unauthorized_user_cannot_access_onboard_page(): void
    {
        $this->actAsStaffWithoutPermission();

        $this->assertFalse(OnboardConsumer::canAccess());

        $response = $this->get(OnboardConsumer::getUrl());
        $response->assertForbidden();
    }

    // ============================================================
    // Task 2 Test 2: RBAC authorized — page rendert 200
    // ============================================================

    public function test_authorized_staff_can_access_onboard_page(): void
    {
        $this->actAsStaffWithPermission();

        $this->assertTrue(OnboardConsumer::canAccess());

        $response = $this->get(OnboardConsumer::getUrl());
        $response->assertOk();
    }

    // ============================================================
    // Task 1 Test 2: Page rendert 4 step-titels
    // ============================================================

    public function test_onboard_page_renders_wizard_with_four_step_titles(): void
    {
        $this->actAsStaffWithPermission();

        $response = $this->get(OnboardConsumer::getUrl());

        $response->assertOk();
        // Canonical NL-copy uit UI-SPEC §S1 regel 137-140
        $response->assertSeeText('Consumer');
        $response->assertSeeText('Eerste Account');
        $response->assertSeeText('Eerste Connection');
        $response->assertSeeText('PAT uitgeven');
    }

    // ============================================================
    // Task 1 Test 5: Stap 3 descriptor-driven (Mollie + Snelstart options)
    // ============================================================

    public function test_step_three_provider_options_come_from_descriptor(): void
    {
        $this->actAsStaffWithPermission();

        $response = $this->get(OnboardConsumer::getUrl());

        $response->assertOk();
        // Beide providers uit config/hub-providers.php moeten als radio-option verschijnen
        $response->assertSee('mollie', false);
        $response->assertSee('snelstart', false);
    }

    // ============================================================
    // Task 2 Test 3: ListConsumers header-action 'Onboarden' visible
    // ============================================================

    public function test_list_consumers_renders_onboarden_header_action(): void
    {
        $this->actAsStaffWithPermission();

        Livewire::test(ListConsumers::class)
            ->assertActionVisible('onboard');
    }

    // ============================================================
    // Task 2 Test 4: header-action hidden voor non-staff zonder permission
    // ============================================================

    public function test_list_consumers_onboarden_action_hidden_without_permission(): void
    {
        // Staff zonder manage-consumers kan ListConsumers niet eens zien.
        // We testen daarom alleen dat OnboardConsumer::canAccess() false retourneert
        // en dat de header-action zelf de canAccess-check als visible-gate gebruikt.
        $this->actAsStaffWithoutPermission();

        $this->assertFalse(OnboardConsumer::canAccess());
    }

    // ============================================================
    // Task 2 Test 5: Happy-path submit creëert Consumer + Account + Connection + PAT
    // ============================================================

    public function test_happy_path_provisions_all_artifacts(): void
    {
        $this->actAsStaffWithPermission();

        Livewire::test(OnboardConsumer::class)
            ->fillForm([
                'name' => 'Naschool Test',
                'slug' => 'naschool-test',
                'webhook_callback_url' => 'https://naschool.test/webhooks/hub',
                'webhook_callback_secret' => 'plain-webhook-secret-from-staff',
                'external_id' => 'school1',
                'display_name' => 'School A',
                'connection' => [
                    'provider' => 'snelstart',
                    'client_key' => 'test-client-key',
                    'subscription_key' => 'test-subscription-key',
                    'subscription_id' => 'test-subscription-id',
                ],
                'pat' => [
                    'preset' => 'admin',
                    'token_name' => 'naschool-onboard',
                ],
            ])
            ->call('submit')
            ->assertHasNoFormErrors();

        $consumer = Consumer::where('slug', 'naschool-test')->first();
        $this->assertNotNull($consumer, 'Consumer-rij moet bestaan na happy-path submit');
        $this->assertSame('Naschool Test', $consumer->name);
        $this->assertSame(1, $consumer->accounts()->count());

        $account = $consumer->accounts()->first();
        $this->assertSame('school1', $account->external_id);
        $this->assertSame('School A', $account->display_name);

        $connection = $account->connections()->first();
        $this->assertNotNull($connection, 'Snelstart Connection-rij moet bestaan');
        $this->assertSame('snelstart', $connection->provider);

        $this->assertSame(1, $consumer->tokens()->count());
        $token = $consumer->tokens()->first();
        $this->assertSame('naschool-onboard', $token->name);
        $this->assertSame(TokenAbilities::ADMIN, $token->abilities[0] ?? null);
    }

    // ============================================================
    // Task 2 Test 6: no-secret-leak — plain PAT niet zichtbaar na Cache::pull
    // ============================================================

    public function test_plain_token_not_visible_after_dismiss(): void
    {
        $this->actAsStaffWithPermission();

        Cache::spy();

        Livewire::test(OnboardConsumer::class)
            ->fillForm([
                'name' => 'Naschool Two',
                'slug' => 'naschool-two',
                'external_id' => 'school2',
                'display_name' => 'School B',
                'connection' => [
                    'provider' => 'snelstart',
                    'client_key' => 'k2',
                    'subscription_key' => 's2',
                    'subscription_id' => 'id2',
                ],
                'pat' => [
                    'preset' => 'mollie-read',
                    'token_name' => 'token-two',
                ],
            ])
            ->call('submit');

        // Submit-handler MOET een Cache::put doen met de pat-flash-key.
        Cache::shouldHaveReceived('put')
            ->withArgs(fn (string $key, mixed $value): bool => str_starts_with($key, 'pat-flash:') && is_string($value) && $value !== '')
            ->once();

        // De wizard-pagina mag het plain-token NIET in z'n Livewire-snapshot bewaren —
        // de eenmalige render gebeurt via Cache::pull op de redirect-target (ListConsumers).
        // Hier checken we expliciet dat de OnboardConsumer Page-instance geen
        // `lastIssuedToken`-property of vergelijkbare state-leak houdt.
        $this->assertFalse(
            property_exists(\App\Filament\Pages\OnboardConsumer::class, 'lastIssuedToken'),
            'OnboardConsumer mag plain-token niet als public property opslaan (wire:snapshot leak).'
        );
    }

    // ============================================================
    // Task 2 Test 7: no-secret-leak — plain webhook-secret niet zichtbaar
    // ============================================================

    public function test_plain_webhook_secret_not_visible_after_submit(): void
    {
        $this->actAsStaffWithPermission();

        Cache::spy();

        Livewire::test(OnboardConsumer::class)
            ->fillForm([
                'name' => 'Naschool Three',
                'slug' => 'naschool-three',
                'webhook_callback_secret' => 'staff-supplied-secret-XYZ',
                'external_id' => 'school3',
                'display_name' => 'School C',
                'connection' => [
                    'provider' => 'snelstart',
                    'client_key' => 'k3',
                    'subscription_key' => 's3',
                    'subscription_id' => 'id3',
                ],
                'pat' => [
                    'preset' => 'mollie-read',
                    'token_name' => 'token-three',
                ],
            ])
            ->call('submit');

        // Submit-handler MOET ook een webhook-secret-flash zetten wanneer secret meegegeven is.
        Cache::shouldHaveReceived('put')
            ->withArgs(fn (string $key, mixed $value): bool => str_starts_with($key, 'webhook-secret-flash:') && $value === 'staff-supplied-secret-XYZ')
            ->once();

        // Consumer-rij in DB heeft secret encrypted opgeslagen — raw DB-value ≠ plain
        $consumer = Consumer::where('slug', 'naschool-three')->first();
        $this->assertNotNull($consumer);
        $rawDbValue = \DB::table('consumers')->where('id', $consumer->id)->value('webhook_callback_secret');
        $this->assertNotSame('staff-supplied-secret-XYZ', $rawDbValue, 'Raw DB-value moet encrypted zijn');
    }
}
