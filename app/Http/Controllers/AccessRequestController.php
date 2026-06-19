<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccessRequestRequest;
use App\Mail\AccessRequestSubmitted;
use App\Models\AccessRequest;
use App\Support\ProviderShowcase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Publieke /koppelen-intake. Vervangt de handmatige "mail support"-stap door een
 * gestructureerde aanvraag die in access_requests landt; Emeq onboardt daarna via
 * de OnboardConsumer-wizard. Géén auth — wel honeypot + throttle tegen spam.
 */
class AccessRequestController extends Controller
{
    public function create(ProviderShowcase $showcase): Response
    {
        return Inertia::render('koppelen', [
            'providers' => $showcase->summaries(),
        ]);
    }

    public function store(StoreAccessRequestRequest $request): RedirectResponse
    {
        // Honeypot: gevuld = bot. Stille no-op zodat we 'm niet tippen.
        if (! $request->filled('website')) {
            $accessRequest = AccessRequest::create($request->validated());

            // Melding naar Emeq is best-effort: een mail-misconfig mag de
            // aanvraag nooit laten falen.
            try {
                Mail::to(config('mail.from.address', 'support@emeq.nl'))
                    ->send(new AccessRequestSubmitted($accessRequest));
            } catch (\Throwable $e) {
                Log::warning('Access-request melding niet verzonden', ['error' => $e->getMessage()]);
            }
        }

        return redirect()->route('koppelen')->with('submitted', true);
    }
}
