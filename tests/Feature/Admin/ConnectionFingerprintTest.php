<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plan 09-06 Task 3 — no-secret-leak feature-test.
 *
 * Bewijst (T-09-06-01): GEEN plain-text waarde van
 *  access_token / refresh_token / client_key / subscription_key
 * verschijnt in Filament-render — HTTP-response én Livewire-HTML, voor
 * zowel List- als View-pagina.
 */
class ConnectionFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private const RAW_ACCESS_TOKEN = 'access_test-DO-NOT-LEAK-09-06';

    private const RAW_REFRESH_TOKEN = 'refresh_test-DO-NOT-LEAK-09-06';

    private const RAW_CLIENT_KEY = 'CK-test-DO-NOT-LEAK-09-06';

    private const RAW_SUBSCRIPTION_KEY = 'SK-test-DO-NOT-LEAK-09-06';

    private function seedRoles(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-connections']);
    }

    private function makeStaffUser(): User
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('manage-connections');

        return $user;
    }

    /**
     * @return array{0: Connection, 1: Connection}
     */
    private function seedTwoConnections(): array
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        $mollie = Connection::factory()->forMollie()->for($account)->create([
            'access_token' => self::RAW_ACCESS_TOKEN,
            'refresh_token' => self::RAW_REFRESH_TOKEN,
        ]);

        $snelstart = Connection::factory()->forSnelstart()->for($account)->create([
            'client_key' => self::RAW_CLIENT_KEY,
            'subscription_key' => self::RAW_SUBSCRIPTION_KEY,
        ]);

        return [$mollie, $snelstart];
    }

    public function test_list_page_html_contains_no_raw_credentials(): void
    {
        $admin = $this->makeStaffUser();
        $this->seedTwoConnections();

        $response = $this->actingAs($admin)->get('/admin/connections');

        $response->assertOk();
        $response->assertDontSee(self::RAW_ACCESS_TOKEN);
        $response->assertDontSee(self::RAW_REFRESH_TOKEN);
        $response->assertDontSee(self::RAW_CLIENT_KEY);
        $response->assertDontSee(self::RAW_SUBSCRIPTION_KEY);
    }

    public function test_livewire_list_render_contains_no_raw_credentials(): void
    {
        $admin = $this->makeStaffUser();
        $this->seedTwoConnections();

        $this->actingAs($admin);

        $component = Livewire::test(ListConnections::class);
        $html = $component->html();

        $this->assertStringNotContainsString(self::RAW_ACCESS_TOKEN, $html);
        $this->assertStringNotContainsString(self::RAW_REFRESH_TOKEN, $html);
        $this->assertStringNotContainsString(self::RAW_CLIENT_KEY, $html);
        $this->assertStringNotContainsString(self::RAW_SUBSCRIPTION_KEY, $html);
    }

    public function test_view_page_html_contains_no_raw_credentials_mollie(): void
    {
        $admin = $this->makeStaffUser();
        [$mollie] = $this->seedTwoConnections();

        $response = $this->actingAs($admin)->get("/admin/connections/{$mollie->id}");

        $response->assertOk();
        $response->assertDontSee(self::RAW_ACCESS_TOKEN);
        $response->assertDontSee(self::RAW_REFRESH_TOKEN);
    }

    public function test_view_page_html_contains_no_raw_credentials_snelstart(): void
    {
        $admin = $this->makeStaffUser();
        [, $snelstart] = $this->seedTwoConnections();

        $response = $this->actingAs($admin)->get("/admin/connections/{$snelstart->id}");

        $response->assertOk();
        $response->assertDontSee(self::RAW_CLIENT_KEY);
        $response->assertDontSee(self::RAW_SUBSCRIPTION_KEY);
    }
}
