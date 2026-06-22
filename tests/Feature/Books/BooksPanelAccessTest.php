<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Books\Models\BooksCompany;
use App\Filament\Books\Resources\Clients\ClientResource;
use App\Filament\Resources\Consumers\ConsumerResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
 * Boekhouding leeft top-level in het admin-paneel onder de collapsed groep
 * Boekhouding (géén aparte cluster meer). Eén paneel, functiescheiding blijft:
 * super-admin/boekhouder zien de boekhoud-resources, staff niet; boekhouder ziet
 * géén Hub-resources. Toegang per resource via GatedToBoekhouding (rol) resp. de
 * bestaande Hub-permissions.
 */
class BooksPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $company = BooksCompany::create(['name' => 'Emeq']);
        config(['books.company_id' => $company->id]);

        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Role::firstOrCreate(['name' => 'boekhouder']);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_alle_interne_rollen_komen_het_admin_paneel_in(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($this->userWithRole('super-admin')->canAccessPanel($panel));
        $this->assertTrue($this->userWithRole('staff')->canAccessPanel($panel));
        $this->assertTrue($this->userWithRole('boekhouder')->canAccessPanel($panel));
    }

    public function test_los_boekhoud_paneel_bestaat_niet_meer(): void
    {
        $this->actingAs($this->userWithRole('boekhouder'));

        $this->get('/boekhouding')->assertNotFound();
    }

    public function test_boekhouder_bereikt_de_boekhouding_cluster(): void
    {
        $this->actingAs($this->userWithRole('boekhouder'));

        $this->get(ClientResource::getUrl('index'))->assertSuccessful();
    }

    public function test_super_admin_bereikt_de_boekhouding_cluster(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));

        $this->get(ClientResource::getUrl('index'))->assertSuccessful();
    }

    public function test_staff_ziet_de_boekhouding_cluster_niet(): void
    {
        $this->actingAs($this->userWithRole('staff'));

        $this->get(ClientResource::getUrl('index'))->assertForbidden();
    }

    public function test_boekhouder_ziet_geen_hub_resources(): void
    {
        $this->actingAs($this->userWithRole('boekhouder'));

        $this->get(ConsumerResource::getUrl('index'))->assertForbidden();
    }

    public function test_gast_wordt_naar_login_geleid(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
