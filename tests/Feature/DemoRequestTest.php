<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\StoreDemoRequestRequest;
use App\Mail\DemoRequestSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Publieke demo-aanvraag: GET /demo + POST /demo — mail-only (geen
 * persistentie), validatie, honeypot, redirect-terug met success-flash.
 */
class DemoRequestTest extends TestCase
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
            'preferred_slot' => 'Deze week',
            'message' => 'Graag de Exact-koppeling zien.',
            'privacy_accepted' => true,
        ], $overrides);
    }

    public function test_demo_page_renders_with_slots(): void
    {
        $this->get('/demo')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('demo')
                ->where('slots', StoreDemoRequestRequest::SLOTS)
                ->has('seo'));
    }

    public function test_valid_submission_sends_mail_and_redirects_back(): void
    {
        Mail::fake();

        $this->from(route('demo'))
            ->post('/demo', $this->validPayload())
            ->assertRedirect(route('demo'))
            ->assertSessionHas('submitted', true);

        Mail::assertSent(DemoRequestSubmitted::class, function (DemoRequestSubmitted $mail): bool {
            return $mail->demoRequest['company'] === 'Naschool BV'
                && $mail->demoRequest['preferred_slot'] === 'Deze week';
        });
    }

    public function test_submission_without_privacy_consent_is_rejected(): void
    {
        Mail::fake();

        $this->post('/demo', $this->validPayload(['privacy_accepted' => false]))
            ->assertSessionHasErrors('privacy_accepted');

        Mail::assertNothingSent();
    }

    public function test_unknown_slot_is_rejected(): void
    {
        Mail::fake();

        $this->post('/demo', $this->validPayload(['preferred_slot' => 'Gisteren']))
            ->assertSessionHasErrors('preferred_slot');

        Mail::assertNothingSent();
    }

    public function test_honeypot_silently_drops_submission(): void
    {
        Mail::fake();

        $this->from(route('demo'))
            ->post('/demo', $this->validPayload(['website' => 'http://spam.example']))
            ->assertRedirect(route('demo'))
            ->assertSessionHas('submitted', true);

        Mail::assertNothingSent();
    }
}
