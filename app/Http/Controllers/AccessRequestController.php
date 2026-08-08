<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccessRequestRequest;
use App\Mail\AccessRequestSubmitted;
use App\Models\AccessRequest;
use App\Support\ProviderShowcase;
use App\Support\Seo\Schema;
use App\Support\Seo\SeoMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Publieke koppel-intake. Het formulier staat op de eigen /koppelen-pagina én op
 * elke partner-pagina (preselect op die provider) en POST hierheen; de aanvraag
 * landt in access_requests en Emeq onboardt via de OnboardConsumer-wizard.
 * Géén auth — wel honeypot + throttle.
 */
class AccessRequestController extends Controller
{
    public function create(ProviderShowcase $showcase): Response
    {
        return Inertia::render('koppelen', [
            'providers' => $showcase->summaries(),
            'seo' => SeoMeta::make(
                'Start met koppelen',
                'Vraag een koppeling aan: we beoordelen je use-case, richten je omgeving in en '
                    .'sturen je API-token — meestal binnen één werkdag.',
            )->schema(Schema::breadcrumbs([
                ['name' => 'Home', 'url' => route('home')],
                ['name' => 'Start met koppelen', 'url' => route('koppelen')],
            ])),
        ]);
    }

    public function store(StoreAccessRequestRequest $request): RedirectResponse
    {
        // Honeypot: gevuld = bot. Stille no-op zodat we 'm niet tippen.
        if (! $request->filled('website')) {
            // `privacy_accepted` is gevalideerd op `accepted` maar niet fillable;
            // we leggen het akkoord vast als tijdstip.
            $accessRequest = AccessRequest::create($request->validated() + [
                'privacy_accepted_at' => now(),
            ]);

            // Melding naar Emeq is best-effort: een mail-misconfig mag de
            // aanvraag nooit laten falen.
            try {
                Mail::to(config('mail.from.address', 'support@emeq.nl'))
                    ->send(new AccessRequestSubmitted($accessRequest));
            } catch (\Throwable $e) {
                Log::warning('Access-request melding niet verzonden', ['error' => $e->getMessage()]);
            }
        }

        // Terug naar de pagina waar het formulier staat (/koppelen of een
        // partner-pagina) zodat de success-state daar landt.
        return redirect()
            ->back(fallback: route('koppelen'))
            ->with('submitted', true);
    }
}
