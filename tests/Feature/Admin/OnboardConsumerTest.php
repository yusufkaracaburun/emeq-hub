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

    public function test_unauthorized_user_cannot_access_onboard_page(): void
    {
        $this->actAsStaffWithoutPermission();

        $this->assertFalse(OnboardConsumer::canAccess());

        $response = $this->get(OnboardConsumer::getUrl());
        $response->assertForbidden();
    }

    public function test_authorized_staff_can_access_onboard_page(): void
    {
        $this->actAsStaffWithPermission();

        $this->assertTrue(OnboardConsumer::canAccess());

        $response = $this->get(OnboardConsumer::getUrl());
        $response->assertOk();
    }

    public function test_onboard_page_renders_two_step_wizard(): void
    {
        $this->actAsStaffWithPermission();

        $response = $this->get(OnboardConsumer::getUrl());

        $response->assertOk();
        $response->assertSeeText('Consumer');
        $response->assertSeeText('PAT uitgeven');
        $response->assertDontSeeText('Eerste Account');
        $response->assertDontSeeText('Eerste Connection');
    }

    public function test_list_consumers_renders_onboarden_header_action(): void
    {
        $this->actAsStaffWithPermission();

        Livewire::test(ListConsumers::class)
            ->assertActionVisible('onboard');
    }

    public function test_list_consumers_onboarden_action_hidden_without_permission(): void
    {
        $this->actAsStaffWithoutPermission();

        $this->assertFalse(OnboardConsumer::canAccess());
    }

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

        $this->assertSame(0, $consumer->accounts()->count(), 'Wizard mag geen Account aanmaken');
        $this->assertSame(0, $consumer->connections()->count(), 'Wizard mag geen Connection aanmaken');

        $this->assertSame(1, $consumer->tokens()->count());
        $token = $consumer->tokens()->first();
        $this->assertSame('naschool-onboard', $token->name);
        $this->assertSame(TokenAbilities::ADMIN, $token->abilities[0] ?? null);
    }

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

        Cache::shouldHaveReceived('put')
            ->withArgs(fn (string $key, mixed $value): bool => str_starts_with($key, 'pat-flash:user:') && is_string($value) && $value !== '')
            ->once();

        $this->assertFalse(
            property_exists(OnboardConsumer::class, 'lastIssuedToken'),
            'OnboardConsumer mag plain-token niet als public property opslaan (wire:snapshot leak).'
        );
    }

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

        Cache::shouldHaveReceived('put')
            ->withArgs(fn (string $key, mixed $value): bool => str_starts_with($key, 'webhook-secret-flash:user:') && $value === 'staff-supplied-secret-XYZ')
            ->once();

        $consumer = Consumer::where('slug', 'naschool-three')->first();
        $this->assertNotNull($consumer);
        $rawDbValue = \DB::table('consumers')->where('id', $consumer->id)->value('webhook_callback_secret');
        $this->assertNotSame('staff-supplied-secret-XYZ', $rawDbValue, 'Raw DB-value moet encrypted zijn');
    }

    public function test_plain_token_is_rendered_on_list_consumers_after_wizard_submit(): void
    {
        $this->actAsStaffWithPermission();

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

        $plainToken = Cache::get('pat-flash:user:'.auth()->id());
        $this->assertNotNull($plainToken, 'Wizard moet plain token in user-scoped cache zetten');
        $this->assertIsString($plainToken);
        $this->assertNotSame('', $plainToken);

        $response = $this->get(ConsumerResource::getUrl());
        $response->assertOk();
        $response->assertSee($plainToken, escape: false);
        $response->assertSee('flash-token');
    }

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

    public function test_invalid_argument_from_service_surfaces_actionable_message(): void
    {
        $this->actAsStaffWithPermission();

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

        Notification::assertNotified(
            Notification::make()
                ->title('Validatie mislukt')
                ->body('Onbekende abilities: snelstart:demolish-universe')
                ->danger()
        );
    }
}
