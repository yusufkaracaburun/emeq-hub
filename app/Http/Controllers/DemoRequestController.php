<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreDemoRequestRequest;
use App\Mail\DemoRequestSubmitted;
use App\Support\Seo\Schema;
use App\Support\Seo\SeoMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Publieke demo-aanvraag. Mail-only, geen persistentie: de aanvraag gaat als
 * melding naar Emeq en de lead volgt via e-mail. Faalt de mail, dan loggen we
 * de payload op error-niveau zodat de lead niet stilletjes verdwijnt.
 * Géén auth — wel honeypot + throttle.
 */
class DemoRequestController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('demo', [
            'slots' => StoreDemoRequestRequest::SLOTS,
            'seo' => SeoMeta::make(
                'Demo aanvragen',
                'Plan een demo van de emeq Hub: we laten zien hoe een koppeling met Exact Online '
                    .'werkt en wat je er in je eigen product mee bouwt.',
            )->schema(Schema::breadcrumbs([
                ['name' => 'Home', 'url' => route('home')],
                ['name' => 'Demo aanvragen', 'url' => route('demo')],
            ])),
        ]);
    }

    public function store(StoreDemoRequestRequest $request): RedirectResponse
    {
        // Honeypot: gevuld = bot. Stille no-op zodat we 'm niet tippen.
        if (! $request->filled('website')) {
            try {
                Mail::to(config('mail.from.address', 'support@emeq.nl'))
                    ->send(new DemoRequestSubmitted($request->validated()));
            } catch (\Throwable $e) {
                Log::error('Demo-aanvraag melding niet verzonden — lead alleen in deze log', [
                    'error' => $e->getMessage(),
                    'demo_request' => $request->validated(),
                ]);
            }
        }

        return redirect()
            ->back(fallback: route('demo'))
            ->with('submitted', true);
    }
}
