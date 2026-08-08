<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ProviderShowcase;
use App\Support\Seo\Schema;
use App\Support\Seo\SeoMeta;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Publieke integraties-showcase. Toont de providers die de Hub ondersteunt en
 * wat ze leveren — géén tenant-data. De content komt uit ProviderShowcase
 * (config/hub-providers.php ∩ config/partner-showcase.php).
 */
class PartnersController extends Controller
{
    public function __construct(private readonly ProviderShowcase $showcase) {}

    public function index(): Response
    {
        $providers = $this->showcase->summaries();
        $labels = array_column($providers, 'label');

        return Inertia::render('partners/index', [
            'providers' => $providers,
            'seo' => SeoMeta::make(
                'Integraties — '.implode(', ', $labels),
                'Alle koppelingen die via de emeq Hub beschikbaar zijn: '.implode(', ', $labels)
                    .'. Eén API-contract, ongeacht hoeveel systemen je koppelt.',
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
            // Alle showcase-providers voor de integratie-keuze in het koppel-formulier.
            'providers' => $this->showcase->summaries(),
            'seo' => $this->detailSeo($detail),
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function detailSeo(array $detail): SeoMeta
    {
        $label = $detail['label'];
        $url = route('partners.show', $detail['key']);

        // featureList voedt de GEO-kant: concrete, citeerbare mogelijkheden in
        // plaats van marketing-taal. Capabilities eerst, endpoints als fallback
        // voor providers die nog geen use-case-copy hebben.
        $features = array_column($detail['capabilities'] ?? [], 'title');

        foreach ($detail['endpoints'] ?? [] as $endpoint) {
            $features[] = $endpoint['method'].' '.$endpoint['path'];
        }

        return SeoMeta::make(
            $label.'-koppeling via één API',
            // Afkappen knipt midden in een woord; geschreven copy gaat voor.
            $detail['meta_description'] ?? Str::limit($detail['summary'], 155),
            $url,
        )->schema(
            Schema::breadcrumbs([
                ['name' => 'Home', 'url' => route('home')],
                ['name' => 'Integraties', 'url' => route('partners.index')],
                ['name' => $label, 'url' => $url],
            ]),
            Schema::integration(
                $label.'-koppeling — emeq Hub',
                $detail['summary'],
                $url,
                $features,
            ),
        );
    }
}
