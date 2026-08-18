<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Pages\OnboardConsumer;
use App\Models\AccessRequest;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccessRequestOnboardTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaff(): User
    {
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-consumers']);

        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('manage-consumers');
        $this->actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function snelstartConnection(string $idSuffix): array
    {
        return [
            'provider' => 'snelstart',
            'client_key' => 'test-client-key',
            'subscription_key' => 'test-subscription-key',
            'subscription_id' => 'test-sub-'.$idSuffix,
        ];
    }

    public function test_onboard_page_prefills_from_access_request(): void
    {
        $this->actAsStaff();

        $request = AccessRequest::factory()->create([
            'company' => 'Naschool BV',
            'app_url' => 'https://app.naschool.test',
            'status' => 'new',
        ]);

        $this->get(OnboardConsumer::getUrl(['from_request' => $request->id]))
            ->assertOk()
            ->assertSee('Naschool BV', false)
            ->assertSee('app.naschool.test', false);
    }

    public function test_submit_links_access_request_to_consumer_and_marks_handled(): void
    {
        $this->actAsStaff();

        $request = AccessRequest::factory()->create([
            'company' => 'Koppel BV',
            'providers' => ['snelstart'],
            'status' => 'new',
            'consumer_id' => null,
        ]);

        Livewire::test(OnboardConsumer::class)
            ->set('fromRequest', $request->id)
            ->fillForm([
                'name' => 'Koppel BV',
                'slug' => 'koppel-bv',
                'pat' => [
                    'preset' => 'snelstart-write',
                    'token_name' => 'koppel-onboard',
                ],
            ])
            ->call('submit')
            ->assertHasNoFormErrors();

        $consumer = Consumer::where('slug', 'koppel-bv')->first();
        $this->assertNotNull($consumer);

        $request->refresh();
        $this->assertSame('handled', $request->status);
        $this->assertSame($consumer->id, $request->consumer_id);
    }

    public function test_submit_without_from_request_does_not_touch_access_requests(): void
    {
        $this->actAsStaff();

        Livewire::test(OnboardConsumer::class)
            ->fillForm([
                'name' => 'Los BV',
                'slug' => 'los-bv',
                'pat' => [
                    'preset' => 'snelstart-write',
                    'token_name' => 'los-onboard',
                ],
            ])
            ->call('submit')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('access_requests', 0);
        $this->assertNotNull(Consumer::where('slug', 'los-bv')->first());
    }
}
