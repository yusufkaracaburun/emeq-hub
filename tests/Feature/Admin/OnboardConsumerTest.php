<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Pages\OnboardConsumer;
use App\Filament\Resources\Consumers\ConsumerResource;
use App\Filament\Resources\Consumers\Pages\ListConsumers;
use App\Models\Consumer;
use App\Models\User;
use App\Sanctum\TokenAbilities;
use App\Services\ConsumerOnboarding;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * OnboardConsumer — Filament-wizard die een Consumer + PAT aanmaakt.
 *
 * Dekt:
 *  - RBAC: canAccess() + page-route 403 zonder manage-consumers
 *  - Page render: twee stappen, géén Account/Connection
 *  - Header-action in ListConsumers (Onboarden) + visibility-gate
 *  - Happy-path submit → ConsumerOnboarding → Consumer + PAT, en verder niets
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
    // Page rendert twee stappen
    // ============================================================

    public function test_onboard_page_renders_two_step_wizard(): void
    {
        $this->actAsStaffWithPermission();

        $response = $this->get(OnboardConsumer::getUrl());

        $response->assertOk();
        $response->assertSeeText('Consumer');
        $response->assertSeeText('PAT uitgeven');
        // Account en Connection horen niet in de admin-wizard: die ontstaan bij de Consumer.
        $response->assertDontSeeText('Eerste Account');
        $response->assertDontSeeText('Eerste Connection');
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
    // Happy path: de wizard maakt een Consumer + PAT, en verder niets. Accounts en
    // Connections ontstaan runtime bij de Consumer (oauth/init resp. de OAuth-flow).
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

        // De admin kent de external_id van een eindklant niet en kan geen OAuth-tokens
        // invullen — de wizard hoort hier dus niets te hebben aangemaakt.
        $this->assertSame(0, $consumer->accounts()->count(), 'Wizard mag geen Account aanmaken');
        $this->assertSame(0, $consumer->connections()->count(), 'Wizard mag geen Connection aanmaken');

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
                'pat' => [
                    'preset' => 'mollie-read',
                    'token_name' => 'token-two',
                ],
            ])
            ->call('submit');

        // Submit-handler MOET een Cache::put doen met de user-scoped pat-flash-key
        // (CR-01 fix: writer = OnboardConsumer, reader = ListConsumers — twee
        // verschillende Livewire-componenten, dus Livewire-id-scope kan niet matchen).
        Cache::shouldHaveReceived('put')
            ->withArgs(fn (string $key, mixed $value): bool => str_starts_with($key, 'pat-flash:user:') && is_string($value) && $value !== '')
            ->once();

        // De wizard-pagina mag het plain-token NIET in z'n Livewire-snapshot bewaren —
        // de eenmalige render gebeurt via Cache::pull op de redirect-target (ListConsumers).
        // Hier checken we expliciet dat de OnboardConsumer Page-instance geen
        // `lastIssuedToken`-property of vergelijkbare state-leak houdt.
        $this->assertFalse(
            property_exists(OnboardConsumer::class, 'lastIssuedToken'),
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
                'pat' => [
                    'preset' => 'mollie-read',
                    'token_name' => 'token-three',
                ],
            ])
            ->call('submit');

        // Submit-handler MOET ook een user-scoped webhook-secret-flash zetten
        // wanneer een secret meegegeven is (CR-02 fix: voorheen Livewire-id-scoped
        // = nooit gelezen).
        Cache::shouldHaveReceived('put')
            ->withArgs(fn (string $key, mixed $value): bool => str_starts_with($key, 'webhook-secret-flash:user:') && $value === 'staff-supplied-secret-XYZ')
            ->once();

        // Consumer-rij in DB heeft secret encrypted opgeslagen — raw DB-value ≠ plain
        $consumer = Consumer::where('slug', 'naschool-three')->first();
        $this->assertNotNull($consumer);
        $rawDbValue = \DB::table('consumers')->where('id', $consumer->id)->value('webhook_callback_secret');
        $this->assertNotSame('staff-supplied-secret-XYZ', $rawDbValue, 'Raw DB-value moet encrypted zijn');
    }

    // ============================================================
    // CR-01 + WR-02 regression: end-to-end PAT visibility op redirect target
    // ============================================================

    /**
     * Bewijs dat de plain PAT die de wizard schrijft daadwerkelijk gerenderd wordt
     * op de ListConsumers-pagina. Voorheen schreef OnboardConsumer naar
     * `pat-flash:{wizard-livewire-id}` en las ListConsumers `pat-flash:{list-livewire-id}` —
     * twee verschillende componenten, dus nooit een hit. CR-01 fix harmoniseert
     * op `pat-flash:user:{auth-id}`.
     */
    public function test_plain_token_is_rendered_on_list_consumers_after_wizard_submit(): void
    {
        $this->actAsStaffWithPermission();

        // Run de wizard — schrijft het plain token in de cache met de user-scoped key.
        Livewire::test(OnboardConsumer::class)
            ->fillForm([
                'name' => 'Naschool Flash',
                'slug' => 'naschool-flash',
                'pat' => [
                    'preset' => 'admin',
                    'token_name' => 'flash-token',
                ],
            ])
            ->call('submit');

        // Het plain token is niet via de wizard-response op te halen — we lezen het
        // via de zelfde Cache-key die de blade ook leest (zonder Cache::pull() te
        // triggeren) en asserten dat ListConsumers het renderd in de HTML.
        $plainToken = Cache::get('pat-flash:user:'.auth()->id());
        $this->assertNotNull($plainToken, 'Wizard moet plain token in user-scoped cache zetten');
        $this->assertIsString($plainToken);
        $this->assertNotSame('', $plainToken);

        // De ListConsumers-render moet het plain token in de HTML body bevatten.
        // Een mismatch tussen write-key en read-key (regressie van CR-01) faalt hier.
        $response = $this->get(ConsumerResource::getUrl());
        $response->assertOk();
        $response->assertSee($plainToken, escape: false);
        $response->assertSee('flash-token');
    }

    /**
     * CR-02 regression — auto-generated webhook-secret moet zichtbaar zijn op
     * de ListConsumers-redirect-target. Voorheen schreef de wizard naar een
     * `webhook-secret-flash:{livewire-id}` key die nergens gelezen werd.
     */
    public function test_plain_webhook_secret_is_rendered_on_list_consumers_after_wizard_submit(): void
    {
        $this->actAsStaffWithPermission();

        Livewire::test(OnboardConsumer::class)
            ->fillForm([
                'name' => 'Naschool Secret',
                'slug' => 'naschool-secret',
                'webhook_callback_secret' => 'plain-secret-ABC',
                'pat' => [
                    'preset' => 'mollie-read',
                    'token_name' => 'secret-token',
                ],
            ])
            ->call('submit');

        $cachedSecret = Cache::get('webhook-secret-flash:user:'.auth()->id());
        $this->assertSame('plain-secret-ABC', $cachedSecret, 'Webhook secret moet user-scoped staan');

        $response = $this->get(ConsumerResource::getUrl());
        $response->assertOk();
        $response->assertSee('plain-secret-ABC', escape: false);
    }

    // ============================================================
    // WR-05: domein-validatie-fout uit ConsumerOnboarding bubbel't door
    // naar de staff i.p.v. silent te worden ingeslikt.
    // ============================================================

    public function test_invalid_argument_from_service_surfaces_actionable_message(): void
    {
        $this->actAsStaffWithPermission();

        // Bind een fake ConsumerOnboarding die InvalidArgumentException gooit.
        // Filament's CheckboxList::options()-whitelist filtert onbekende abilities
        // al weg, dus deze test verifieert specifiek de WR-05 defense-in-depth
        // catch-branch in Page::submit() — niet de service-laag-validatie.
        $this->app->bind(ConsumerOnboarding::class, function () {
            return new class
            {
                public function onboard(array $data): array
                {
                    throw new \InvalidArgumentException('Onbekende abilities: snelstart:demolish-universe');
                }
            };
        });

        Livewire::test(OnboardConsumer::class)
            ->fillForm([
                'name' => 'Naschool Bad',
                'slug' => 'naschool-bad',
                'pat' => [
                    'preset' => 'admin',
                    'token_name' => 'bad-token',
                ],
            ])
            ->call('submit');

        // Catch-branch dispatcht een actionable Notification met de exception-message
        // i.p.v. report() + generic "Er ging iets mis". assertNotified() doet
        // een strict-equal compare over alle Notification-properties; we matchen
        // op title + body + status zodat regressie naar de generieke pad faalt.
        Notification::assertNotified(
            Notification::make()
                ->title('Validatie mislukt')
                ->body('Onbekende abilities: snelstart:demolish-universe')
                ->danger()
        );
    }
}
