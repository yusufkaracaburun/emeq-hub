<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Consumers\Pages\ViewConsumer;
use App\Filament\Resources\Consumers\RelationManagers\TokensRelationManager;
use App\Models\Consumer;
use App\Models\User;
use App\Sanctum\TokenAbilities;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConsumerTokensRelationManagerTest extends TestCase
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

    public function test_uitgegeven_tokens_zijn_zichtbaar(): void
    {
        $this->actAsStaff();

        $consumer = Consumer::factory()->create();
        $consumer->createToken('naschool-prod', [TokenAbilities::EXACT_READ]);

        Livewire::test(TokensRelationManager::class, [
            'ownerRecord' => $consumer,
            'pageClass' => ViewConsumer::class,
        ])->assertSee('naschool-prod');
    }

    public function test_token_kan_worden_ingetrokken(): void
    {
        $this->actAsStaff();

        $consumer = Consumer::factory()->create();
        $gelekt = $consumer->createToken('gelekt', [TokenAbilities::EXACT_READ])->accessToken;
        $consumer->createToken('nieuw', [TokenAbilities::EXACT_READ]);

        $this->assertSame(2, $consumer->tokens()->count());

        Livewire::test(TokensRelationManager::class, [
            'ownerRecord' => $consumer,
            'pageClass' => ViewConsumer::class,
        ])->callAction(TestAction::make('delete')->table($gelekt->getKey()));

        $this->assertSame(1, $consumer->fresh()->tokens()->count());
        $this->assertNull($consumer->tokens()->where('name', 'gelekt')->first());
    }
}
