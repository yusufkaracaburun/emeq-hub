<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\StoreDemoRequestRequest;
use App\Mail\DemoRequestSubmitted;
use App\Models\DemoRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Publieke demo-aanvraag: GET /demo + POST /demo — persistentie, melding,
 * validatie, honeypot, redirect-terug met success-flash.
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

    public function test_valid_submission_is_stored_and_notified(): void
    {
        Mail::fake();

        $this->from(route('demo'))
            ->post('/demo', $this->validPayload())
            ->assertRedirect(route('demo'))
            ->assertSessionHas('submitted', true);

        // De lead hoort in de database te staan, niet alleen in een mail: met de
        // log-mailer verdween een aanvraag anders spoorloos.
        $demoRequest = DemoRequest::sole();
        $this->assertSame('Naschool BV', $demoRequest->company);
        $this->assertSame('Deze week', $demoRequest->preferred_slot);
        $this->assertSame('new', $demoRequest->status);
        $this->assertNotNull($demoRequest->privacy_accepted_at);

        Mail::assertQueued(
            DemoRequestSubmitted::class,
            fn (DemoRequestSubmitted $mail): bool => $mail->demoRequest->is($demoRequest)
                && $mail->hasTo(config('mail.notify_address'))
                && $mail->hasReplyTo('dev@naschool.test'),
        );
    }

    public function test_lead_survives_a_failing_notification(): void
    {
        // De melding is een gemak, geen schakel: een mailstoring mag de aanvraag
        // niet laten verdwijnen en de bezoeker geen foutpagina geven.
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('mailer down'));

        $this->from(route('demo'))
            ->post('/demo', $this->validPayload())
            ->assertRedirect(route('demo'))
            ->assertSessionHas('submitted', true);

        $this->assertSame(1, DemoRequest::count());
    }

    public function test_submission_without_privacy_consent_is_rejected(): void
    {
        Mail::fake();

        $this->post('/demo', $this->validPayload(['privacy_accepted' => false]))
            ->assertSessionHasErrors('privacy_accepted');

        $this->assertSame(0, DemoRequest::count());
        Mail::assertNothingOutgoing();
    }

    public function test_unknown_slot_is_rejected(): void
    {
        Mail::fake();

        $this->post('/demo', $this->validPayload(['preferred_slot' => 'Gisteren']))
            ->assertSessionHasErrors('preferred_slot');

        $this->assertSame(0, DemoRequest::count());
        Mail::assertNothingOutgoing();
    }

    public function test_honeypot_silently_drops_submission(): void
    {
        Mail::fake();

        $this->from(route('demo'))
            ->post('/demo', $this->validPayload(['website' => 'http://spam.example']))
            ->assertRedirect(route('demo'))
            ->assertSessionHas('submitted', true);

        $this->assertSame(0, DemoRequest::count());
        Mail::assertNothingOutgoing();
    }
}
