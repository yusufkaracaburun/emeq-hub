<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Publieke marketing-homepage op /. Inertia-pagina, alleen statische
 * provider-copy, geen tenant-data. Indexeerbaar (zie SetNoIndexHeaders).
 */
class HomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_renders_inertia_component_with_providers(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('home')
                ->has('providers', 3, fn (AssertableInertia $provider) => $provider
                    ->hasAll(['key', 'label', 'tagline', 'category', 'summary', 'logo', 'brand'])
                )
            );
    }

    public function test_home_is_indexable(): void
    {
        $this->get('/')->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_home_does_not_leak_tenant_data(): void
    {
        Account::factory()->create(['display_name' => 'Geheime Klant BV']);

        $this->get('/')->assertDontSee('Geheime Klant BV');
    }
}
