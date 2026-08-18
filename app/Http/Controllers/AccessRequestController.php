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
        if (! $request->filled('website')) {
            $accessRequest = AccessRequest::create($request->validated() + [
                'privacy_accepted_at' => now(),
            ]);

            try {
                Mail::to(config('mail.notify_address'))->send(new AccessRequestSubmitted($accessRequest));
            } catch (\Throwable $e) {
                Log::warning('Access-request melding niet verzonden', ['error' => $e->getMessage()]);
            }
        }

        return redirect()
            ->back(fallback: route('koppelen'))
            ->with('submitted', true);
    }
}
