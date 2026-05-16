<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Consumers\ConsumerResource;
use App\Filament\Resources\Consumers\Pages\ListConsumers;
use App\Models\Consumer;
use App\Models\User;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
