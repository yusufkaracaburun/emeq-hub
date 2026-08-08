<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Settings\LegalSettings;
use App\Support\ProviderShowcase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Bewaakt de publieke crawler-surface: robots.txt, sitemap, llms.txt en de
 * server-side SEO-payload per pagina. De regressie die dit voorkomt is drift —
 * eerder stond de indexeerbare-lijst in drie bestanden en blokkeerde robots.txt
 * pagina's die de middleware wél indexeerbaar verklaarde.
 */
class PublicSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // De legal-pagina's lezen hun tekst uit de settings-tabel.
        LegalSettings::fake([
            'privacy_statement' => '## Privacy',
            'privacy_updated_at' => '2026-07-18',
            'terms_statement' => '## Voorwaarden',
            'terms_updated_at' => '2026-07-18',
            'dpa_statement' => '## Verwerkersovereenkomst',
            'dpa_updated_at' => '2026-07-18',
        ]);
    }

    /**
     * @return list<array{0:string}>
     */
    public static function publicPages(): array
    {
        return [
            'home' => ['/'],
            'partners-index' => ['/partners'],
            'partners-detail' => ['/partners/exact'],
            'koppelen' => ['/koppelen'],
            'demo' => ['/demo'],
            'support' => ['/support'],
            'privacy' => ['/privacy'],
            'voorwaarden' => ['/voorwaarden'],
            'verwerkersovereenkomst' => ['/verwerkersovereenkomst'],
        ];
    }

    #[DataProvider('publicPages')]
    public function test_public_page_carries_a_complete_seo_payload(string $path): void
    {
        $this->get($path)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('seo.title')
                ->has('seo.description')
                ->has('seo.canonical')
                ->has('seo.image')
                ->has('seo.jsonLd.@graph'));
    }

    #[DataProvider('publicPages')]
    public function test_public_page_declares_the_emeq_entity(string $path): void
    {
        $response = $this->get($path)->assertOk();

        $graph = $this->graph($response->viewData('page')['props']['seo']);
        $types = array_column($graph, '@type');

        $this->assertContains('Organization', $types, 'Zonder Organization-node kan een LLM "emeq" niet als entiteit resolven.');
        $this->assertContains('WebSite', $types);

        $organization = $graph[array_search('Organization', $types, true)];
        $this->assertSame(['https://emeq.nl', 'https://planny.nl'], $organization['sameAs']);
    }

    public function test_title_carries_the_brand_suffix_once(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('seo.title', 'Eén API voor al je productintegraties · '.config('app.name')));
    }

    public function test_partner_detail_exposes_the_integration_as_structured_data(): void
    {
        $response = $this->get('/partners/exact')->assertOk();

        $graph = $this->graph($response->viewData('page')['props']['seo']);
        $types = array_column($graph, '@type');

        $this->assertContains('BreadcrumbList', $types);
        $this->assertContains('SoftwareApplication', $types);

        $application = $graph[array_search('SoftwareApplication', $types, true)];

        // featureList is wat een LLM citeert; leeg = de pagina levert geen feiten.
        $this->assertNotEmpty($application['featureList']);
        $this->assertContains('POST /v1/accounting/documents', $application['featureList']);
    }

    public function test_support_faq_and_structured_data_share_one_source(): void
    {
        $response = $this->get('/support')->assertOk();

        $props = $response->viewData('page')['props'];
        $graph = $this->graph($props['seo']);
        $types = array_column($graph, '@type');

        $faqNode = $graph[array_search('FAQPage', $types, true)];

        $visible = array_column($props['faq'], 'question');
        $structured = array_column($faqNode['mainEntity'], 'name');

        $this->assertSame($visible, $structured);
    }

    public function test_legal_page_publishes_an_iso_date(): void
    {
        $response = $this->get('/privacy')->assertOk();

        $graph = $this->graph($response->viewData('page')['props']['seo']);
        $types = array_column($graph, '@type');
        $webPage = $graph[array_search('WebPage', $types, true)];

        $this->assertSame('2026-07-18', $webPage['dateModified']);
    }

    public function test_sitemap_lists_every_public_page_and_every_provider(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $response->assertHeader('Content-Type', 'application/xml');

        $xml = $response->getContent();
        $this->assertNotFalse(simplexml_load_string($xml), 'Sitemap moet valide XML zijn.');

        foreach (['/partners', '/support', '/privacy', '/voorwaarden', '/verwerkersovereenkomst'] as $path) {
            $this->assertStringContainsString('<loc>'.url($path).'</loc>', $xml);
        }

        foreach (app(ProviderShowcase::class)->summaries() as $provider) {
            $this->assertStringContainsString(
                '<loc>'.url('/partners/'.$provider['key']).'</loc>',
                $xml,
                "Provider {$provider['key']} ontbreekt in de sitemap.",
            );
        }
    }

    public function test_robots_opens_the_public_surface_and_names_the_sitemap_in_production(): void
    {
        $this->app['env'] = 'production';

        $body = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString('Sitemap: '.route('sitemap'), $body);
        $this->assertStringContainsString('Disallow: /admin', $body);
        $this->assertStringContainsString('Disallow: /v1/', $body);

        // De legal-pagina's waren hiervóór geblokkeerd terwijl de middleware ze
        // indexeerbaar verklaarde — precies de drift die dit bestand bewaakt.
        $this->assertStringNotContainsString("\nDisallow: /\n", $body);

        // Alleen de crawlers die naar de bron linken.
        foreach (['OAI-SearchBot', 'PerplexityBot', 'Claude-SearchBot'] as $agent) {
            $this->assertStringContainsString('User-agent: '.$agent, $body);
        }

        // Trainings-crawlers horen hier niet: Cloudflare weert die zone-breed,
        // en ze hier tóch noemen levert twee groepen voor dezelfde user-agent
        // op — een tegenstrijdig bestand zonder effect.
        foreach (['GPTBot', 'ClaudeBot', 'Google-Extended', 'CCBot'] as $agent) {
            $this->assertStringNotContainsString('User-agent: '.$agent, $body);
        }
    }

    public function test_robots_closes_everything_outside_production(): void
    {
        $body = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString('Disallow: /', $body);
        $this->assertStringNotContainsString('Sitemap:', $body);
    }

    public function test_llms_txt_describes_the_product_and_its_integrations(): void
    {
        $body = $this->get('/llms.txt')->assertOk()->getContent();

        $this->assertStringContainsString('# emeq Hub', $body);

        foreach (app(ProviderShowcase::class)->summaries() as $provider) {
            $this->assertStringContainsString($provider['label'], $body);
        }
    }

    #[DataProvider('publicPages')]
    public function test_public_page_is_indexable_in_production(string $path): void
    {
        $this->app['env'] = 'production';

        $this->get($path)->assertOk()->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_non_public_routes_stay_noindex_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }

    public function test_everything_is_noindex_outside_production(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }

    /**
     * De props reizen als objecten tot ze ge-json-encode worden; via een
     * round-trip lezen we exact wat de browser en de crawler te zien krijgen.
     *
     * @return list<array<string, mixed>>
     */
    private function graph(mixed $seo): array
    {
        return json_decode(json_encode($seo), true)['jsonLd']['@graph'];
    }
}
