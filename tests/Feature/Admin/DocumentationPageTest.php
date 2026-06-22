<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Pages\Documentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentationPageTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_page_renders_guide_as_html(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));

        Livewire::test(Documentation::class)
            ->assertOk()
            ->assertSee('Consumer-integratiehandleiding');
    }

    public function test_guide_html_renders_markdown_tables_and_headings(): void
    {
        $html = (new Documentation)->guideHtml();

        // GFM-tabel + heading uit de guide bewijzen dat markdown → HTML rendert.
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<h2>Concepten</h2>', $html);
    }
}
