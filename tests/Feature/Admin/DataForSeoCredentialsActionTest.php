<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\ConnectionsRelationManager;
use App\Models\Account;
use App\Models\Connection;
use App\Models\User;
use Emeq\DataForSeoApi\Http\Request\UserDataRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DataForSeoCredentialsActionTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        MockClient::destroyGlobal();
        Permission::firstOrCreate(['name' => 'manage-connections']);
        Role::firstOrCreate(['name' => 'super-admin'])->givePermissionTo('manage-connections');
        Role::firstOrCreate(['name' => 'staff'])->givePermissionTo('manage-connections');
        $this->account = Account::factory()->create();
    }

    public function test_super_admin_creates_connection_after_live_check(): void
    {
        $mockClient = MockClient::global([UserDataRequest::class => $this->userData(42.5)]);

        $this->relationManagerAs('super-admin')
            ->callTableAction('dataForSeoCredentials', data: ['login' => 'api@example.com', 'password' => 'secret:with-colon'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('DataForSEO-inlog opgeslagen');

        $mockClient->assertSentCount(1);
        $connection = $this->account->connections()->where('provider', 'dataforseo')->sole();
        $this->assertSame('api@example.com:secret:with-colon', $connection->access_token);
        $this->assertSame('active', $connection->status);
    }

    public function test_existing_connection_gets_its_token_replaced(): void
    {
        MockClient::global([UserDataRequest::class => $this->userData(10.0)]);
        $existing = Connection::factory()->forDataForSeo()->for($this->account)->create();

        $this->relationManagerAs('super-admin')
            ->callTableAction('dataForSeoCredentials', data: ['login' => 'api@example.com', 'password' => 'new-password'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, $this->account->connections()->where('provider', 'dataforseo')->count());
        $this->assertSame('api@example.com:new-password', $existing->fresh()->access_token);
    }

    public function test_rejected_credentials_are_not_saved(): void
    {
        MockClient::global([UserDataRequest::class => MockResponse::make(['status_code' => 40100], 401)]);

        $this->relationManagerAs('super-admin')
            ->callTableAction('dataForSeoCredentials', data: ['login' => 'api@example.com', 'password' => 'wrong'])
            ->assertNotified('DataForSEO weigert deze inlog');

        $this->assertSame(0, $this->account->connections()->count());
    }

    public function test_action_is_hidden_for_staff(): void
    {
        $this->relationManagerAs('staff')
            ->assertTableActionHidden('dataForSeoCredentials');
    }

    private function relationManagerAs(string $role): Testable
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return Livewire::test(ConnectionsRelationManager::class, [
            'ownerRecord' => $this->account,
            'pageClass' => ViewAccount::class,
        ]);
    }

    private function userData(float $balance): MockResponse
    {
        return MockResponse::make([
            'status_code' => 20000,
            'tasks_error' => 0,
            'tasks' => [[
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'result' => [['login' => 'api@example.com', 'money' => ['total' => 50.5, 'balance' => $balance]]],
            ]],
        ]);
    }
}
