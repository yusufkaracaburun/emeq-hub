<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ProviderShowcase;
use App\Support\Seo\Schema;
use App\Support\Seo\SeoMeta;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PartnersController extends Controller
{
    public function __construct(private readonly ProviderShowcase $showcase) {}

    public function index(): Response
    {
        $providers = $this->showcase->summaries();
        $labels = array_column($providers, 'label');
        $live = array_column(array_filter($providers, fn (array $p): bool => $p['live']), 'label');
        $coming = array_column(array_filter($providers, fn (array $p): bool => ! $p['live']), 'label');

        $status = array_filter([
            $live === [] ? null : implode(' en ', $live).(count($live) === 1 ? ' is' : ' zijn').' live via de emeq Hub',
            $coming === [] ? null : implode(' en ', $coming).(count($coming) === 1 ? ' volgt' : ' volgen'),
        ]);

        return Inertia::render('partners/index', [
            'providers' => $providers,
            'seo' => SeoMeta::make(
                'Integraties · '.implode(', ', $labels),
                implode('; ', $status).'. Eén API-contract, ongeacht hoeveel systemen je koppelt.',
            )->schema(
                Schema::breadcrumbs([
                    ['name' => 'Home', 'url' => route('home')],
                    ['name' => 'Integraties', 'url' => route('partners.index')],
                ]),
            ),
        ]);
    }

    public function show(string $provider): Response
    {
        $detail = $this->showcase->detail($provider);

        abort_if($detail === null, 404);

        return Inertia::render('partners/show', [
            'provider' => $detail,
            'providers' => $this->showcase->summaries(),
            'seo' => $this->detailSeo($detail),
        ]);
    }

    /** @param  array<string, mixed>  $detail */
    private function detailSeo(array $detail): SeoMeta
    {
        $label = $detail['label'];
        $url = route('partners.show', $detail['key']);

        $features = array_column($detail['capabilities'] ?? [], 'title');

        foreach ($detail['endpoints'] ?? [] as $endpoint) {
            $features[] = $endpoint['method'].' '.$endpoint['path'];
        }

        return SeoMeta::make(
            $label.'-koppeling via één API',
            $detail['meta_description'] ?? Str::limit($detail['summary'], 155),
            $url,
        )->schema(
            Schema::breadcrumbs([
                ['name' => 'Home', 'url' => route('home')],
                ['name' => 'Integraties', 'url' => route('partners.index')],
                ['name' => $label, 'url' => $url],
            ]),
            Schema::integration(
                $label.'-koppeling · emeq Hub',
                $detail['summary'],
                $url,
                $features,
            ),
        );
    }
}
