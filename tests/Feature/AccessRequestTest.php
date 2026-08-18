<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\AccessRequests\AccessRequestResource;
use App\Mail\AccessRequestSubmitted;
use App\Models\AccessRequest;
use App\Support\PublicPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AccessRequestTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company' => 'Naschool BV',
            'contact_name' => 'Yusuf',
            'email' => 'dev@naschool.test',
            'app_url' => 'https://app.naschool.test',
            'providers' => ['exact', 'mollie'],
            'message' => 'We willen koppelen voor facturatie.',
            'privacy_accepted' => true,
        ], $overrides);
    }

    public function test_koppelen_page_renders_with_providers(): void
    {
        $this->get('/koppelen')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('koppelen')
                ->has('providers')
                ->has('seo'));
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
        $this->assertNotNull($record->privacy_accepted_at);

        Mail::assertQueued(AccessRequestSubmitted::class);
    }

    public function test_submission_without_privacy_consent_is_rejected(): void
    {
        Mail::fake();

        $this->post('/koppelen', $this->validPayload(['privacy_accepted' => false]))
            ->assertSessionHasErrors('privacy_accepted');

        $this->assertDatabaseCount('access_requests', 0);
        Mail::assertNothingOutgoing();
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
        Mail::assertNothingOutgoing();
    }

    public function test_robots_txt_does_not_block_partners(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /');

        $this->assertNotContains('/partners', PublicPages::DISALLOWED_PATHS);
    }

    public function test_navigation_badge_counts_new_requests(): void
    {
        AccessRequest::factory()->count(2)->create(['status' => 'new']);
        AccessRequest::factory()->create(['status' => 'handled']);

        $this->assertSame('2', AccessRequestResource::getNavigationBadge());
    }
}
