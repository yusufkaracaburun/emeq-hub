<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Publieke /partners-showcase: Inertia-pagina's, alleen statische provider-copy,
 * geen tenant-data. Indexeerbaar (uitgezonderd van SetNoIndexHeaders).
 */
class PartnersShowcaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_inertia_component_with_all_providers(): void
    {
        $this->get('/partners')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('partners/index')
                ->has('providers', 3, fn (AssertableInertia $provider) => $provider
                    ->hasAll(['key', 'label', 'tagline', 'category', 'summary'])
                )
            );
    }

    public function test_show_renders_provider_detail(): void
    {
        $this->get('/partners/exact')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('partners/show')
                ->where('provider.key', 'exact')
                ->where('provider.label', 'Exact Online')
                ->has('provider.capabilities')
                ->has('provider.connect_steps')
            );
    }

    public function test_exact_show_exposes_how_it_works_and_endpoint_map(): void
    {
        $this->get('/partners/exact')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('partners/show')
                ->has('provider.how_it_works')
                ->has('provider.endpoints', 8, fn (AssertableInertia $endpoint) => $endpoint
                    ->hasAll(['method', 'path', 'target', 'description'])
                )
            );
    }

    public function test_each_registered_provider_has_a_showcase_page(): void
    {
        foreach (['exact', 'mollie', 'snelstart'] as $provider) {
            $this->get("/partners/{$provider}")->assertOk();
        }
    }

    public function test_unknown_provider_returns_404(): void
    {
        $this->get('/partners/onbekend')->assertNotFound();
    }

    public function test_showcase_is_indexable(): void
    {
        $this->get('/partners')->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_non_showcase_routes_stay_noindex(): void
    {
        $this->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }

    public function test_showcase_does_not_leak_tenant_data(): void
    {
        Account::factory()->create(['display_name' => 'Geheime Klant BV']);

        $this->get('/partners')->assertDontSee('Geheime Klant BV');
        $this->get('/partners/exact')->assertDontSee('Geheime Klant BV');
    }
}
