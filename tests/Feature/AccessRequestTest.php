<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\AccessRequests\AccessRequestResource;
use App\Mail\AccessRequestSubmitted;
use App\Models\AccessRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Publieke koppel-intake: het formulier staat op elke partner-pagina (preselect),
 * POST /koppelen — opslag, validatie, honeypot, redirect-terug, indexering.
 */
class AccessRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company' => 'Naschool BV',
            'contact_name' => 'Yusuf',
            'email' => 'dev@naschool.test',
            'app_url' => 'https://app.naschool.test',
            'providers' => ['exact', 'mollie'],
            'message' => 'We willen koppelen voor facturatie.',
        ], $overrides);
    }

    public function test_partner_page_renders_with_provider(): void
    {
        $this->get('/partners/exact')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('partners/show')
                ->where('provider.key', 'exact')
            );
    }

    public function test_valid_submission_is_stored_and_redirects_to_partner(): void
    {
        Mail::fake();

        $this->from(route('partners.show', 'exact'))
            ->post('/koppelen', $this->validPayload(['providers' => ['exact']]))
            ->assertRedirect(route('partners.show', 'exact'))
            ->assertSessionHas('submitted', true);

        $this->assertDatabaseCount('access_requests', 1);

        $record = AccessRequest::first();
        $this->assertSame('Naschool BV', $record->company);
        $this->assertSame(['exact'], $record->providers);
        $this->assertSame('new', $record->status);

        Mail::assertSent(AccessRequestSubmitted::class);
    }

    public function test_invalid_submission_fails_validation(): void
    {
        $this->post('/koppelen', $this->validPayload(['email' => '', 'providers' => []]))
            ->assertSessionHasErrors(['email', 'providers']);

        $this->assertDatabaseCount('access_requests', 0);
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $this->post('/koppelen', $this->validPayload(['providers' => ['onbekend']]))
            ->assertSessionHasErrors('providers.0');

        $this->assertDatabaseCount('access_requests', 0);
    }

    public function test_honeypot_silently_drops_submission(): void
    {
        Mail::fake();

        $this->from(route('partners.show', 'exact'))
            ->post('/koppelen', $this->validPayload(['website' => 'http://spam.example']))
            ->assertRedirect(route('partners.show', 'exact'))
            ->assertSessionHas('submitted', true);

        $this->assertDatabaseCount('access_requests', 0);
        Mail::assertNothingSent();
    }

    public function test_partner_page_is_indexable(): void
    {
        $this->get('/partners/exact')->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_robots_txt_allows_partners(): void
    {
        $this->assertStringContainsString('Allow: /partners', file_get_contents(public_path('robots.txt')));
    }

    public function test_navigation_badge_counts_new_requests(): void
    {
        AccessRequest::factory()->count(2)->create(['status' => 'new']);
        AccessRequest::factory()->create(['status' => 'handled']);

        $this->assertSame('2', AccessRequestResource::getNavigationBadge());
    }
}
