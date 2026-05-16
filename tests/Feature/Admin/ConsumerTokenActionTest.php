<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Consumers\ConsumerResource;
use App\Filament\Resources\Consumers\Pages\ListConsumers;
use App\Models\Consumer;
use App\Models\User;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConsumerTokenActionTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-consumers']);
    }

    public function test_staff_user_can_issue_pat_with_mollie_read_preset(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole('staff');
        $admin->givePermissionTo('manage-consumers');
        $consumer = Consumer::factory()->create();

        $this->actingAs($admin);

        Livewire::test(ListConsumers::class)
            ->callTableAction(ConsumerResource::ISSUE_PAT_ACTION, $consumer, [
                'name' => 'Test PAT',
                'preset' => 'mollie-read',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, $consumer->fresh()->tokens()->count());
        $token = $consumer->fresh()->tokens()->first();
        $this->assertSame([TokenAbilities::MOLLIE_READ], $token->abilities);
        $this->assertSame('Test PAT', $token->name);
    }

    public function test_staff_user_can_issue_pat_with_custom_abilities(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole('staff');
        $admin->givePermissionTo('manage-consumers');
        $consumer = Consumer::factory()->create();

        $this->actingAs($admin);

        Livewire::test(ListConsumers::class)
            ->callTableAction(ConsumerResource::ISSUE_PAT_ACTION, $consumer, [
                'name' => 'Custom PAT',
                'preset' => 'custom',
                'abilities' => [TokenAbilities::BILLING_READ],
            ])
            ->assertHasNoTableActionErrors();

        $token = $consumer->fresh()->tokens()->first();
        $this->assertNotNull($token);
        $this->assertSame([TokenAbilities::BILLING_READ], $token->abilities);
        $this->assertSame('Custom PAT', $token->name);
    }

    /**
     * D-9 (WR-06): plain token mag NIET in Livewire's wire:snapshot meer staan.
     * Token-flash gaat via server-side Cache::pull() one-shot.
     */
    public function test_plain_token_not_in_livewire_snapshot(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole('staff');
        $admin->givePermissionTo('manage-consumers');
        $consumer = Consumer::factory()->create();

        $this->actingAs($admin);

        $component = Livewire::test(ListConsumers::class)
            ->callTableAction(ConsumerResource::ISSUE_PAT_ACTION, $consumer, [
                'name' => 'Snapshot PAT',
                'preset' => 'mollie-read',
            ])
            ->assertHasNoTableActionErrors();

        $token = $consumer->fresh()->tokens()->first();
        $this->assertNotNull($token);

        // ListConsumers Livewire-component mag GEEN $lastIssuedPat-state meer hebben
        // (en dus geen plain token in wire:snapshot). De property bestaat niet meer.
        $this->assertFalse(
            property_exists($component->instance(), 'lastIssuedPat'),
            'ListConsumers should not expose $lastIssuedPat — plain token leaks into wire:snapshot.'
        );
    }

    /**
     * D-9 (WR-06): action-callback flasht plain token via Cache::put() naar
     * `pat-flash:{livewire-id}` key (60s TTL). De blade-view pull't 'm one-shot.
     */
    public function test_issue_pat_action_writes_plain_token_to_cache_flash(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole('staff');
        $admin->givePermissionTo('manage-consumers');
        $consumer = Consumer::factory()->create();

        $this->actingAs($admin);

        $component = Livewire::test(ListConsumers::class)
            ->callTableAction(ConsumerResource::ISSUE_PAT_ACTION, $consumer, [
                'name' => 'Cache PAT',
                'preset' => 'mollie-read',
            ])
            ->assertHasNoTableActionErrors();

        $livewireId = $component->instance()->getId();

        $this->assertTrue(Cache::has("pat-flash:{$livewireId}"));
        $this->assertTrue(Cache::has("pat-flash-name:{$livewireId}"));
        $this->assertSame('Cache PAT', Cache::get("pat-flash-name:{$livewireId}"));

        // Pull is destructief — na lezen is de cache leeg.
        $token = Cache::pull("pat-flash:{$livewireId}");
        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $this->assertFalse(Cache::has("pat-flash:{$livewireId}"));
    }
}
