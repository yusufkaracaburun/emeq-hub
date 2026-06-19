<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccessRequestRequest;
use App\Mail\AccessRequestSubmitted;
use App\Models\AccessRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Publieke koppel-intake. Het formulier staat op elke partner-pagina (preselect
 * op die provider) en POST hierheen; de aanvraag landt in access_requests en Emeq
 * onboardt via de OnboardConsumer-wizard. Géén auth — wel honeypot + throttle.
 */
class AccessRequestController extends Controller
{
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

        // Terug naar de partner-pagina waar het formulier staat (preselect-provider
        // = de eerste/enige gekozen integratie) zodat de success-state daar landt.
        return redirect()
            ->route('partners.show', $request->validated('providers')[0])
            ->with('submitted', true);
    }
}
