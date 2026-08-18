<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreDemoRequestRequest;
use App\Mail\DemoRequestSubmitted;
use App\Models\DemoRequest;
use App\Support\Seo\Schema;
use App\Support\Seo\SeoMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

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
        if (! $request->filled('website')) {
            $demoRequest = DemoRequest::create($request->safe()->except('privacy_accepted') + [
                'privacy_accepted_at' => now(),
            ]);

            try {
                Mail::to(config('mail.notify_address'))->send(new DemoRequestSubmitted($demoRequest));
            } catch (\Throwable $e) {
                Log::warning('Demo-aanvraag melding niet verzonden', [
                    'error' => $e->getMessage(),
                    'demo_request_id' => $demoRequest->id,
                ]);
            }
        }

        return redirect()
            ->back(fallback: route('demo'))
            ->with('submitted', true);
    }
}
