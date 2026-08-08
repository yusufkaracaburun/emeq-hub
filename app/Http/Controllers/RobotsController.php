<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PublicPages;
use Illuminate\Http\Response;

/**
 * robots.txt als route in plaats van statisch bestand: het bestand dreef weg
 * van SetNoIndexHeaders (legal-pagina's waren indexeerbaar volgens de
 * middleware maar geblokkeerd volgens robots.txt). Nu één bron: PublicPages.
 *
 * Buiten productie is alles dicht — de dev-tunnel draait op een publiek
 * bereikbaar domein en mag niet geïndexeerd worden.
 */
class RobotsController extends Controller
{
    /**
     * AI-crawlers die de publieke pagina's expliciet mógen lezen: uitsluitend
     * de bots die een lópend antwoord samenstellen en daarbij naar de bron
     * linken. Die leveren verkeer op.
     *
     * Trainings-crawlers (GPTBot, ClaudeBot, Google-Extended,
     * Applebot-Extended, meta-externalagent, Amazonbot, CCBot, Bytespider)
     * staan hier bewust NIET. Cloudflare blokkeert die zone-breed via zijn
     * Managed robots.txt, dat vóór dit blok wordt geïnjecteerd. Zou je ze hier
     * toch noemen, dan bevat het bestand twee groepen voor dezelfde
     * user-agent; die worden samengevoegd (RFC 9309) en Cloudflare's
     * `Disallow: /` wint alsnog — je houdt er alleen een tegenstrijdig bestand
     * aan over. Wil je ze wél toelaten, zet dan eerst Cloudflare's managed
     * robots.txt uit; dit blok is dan de enige bron.
     *
     * Let op: Google-Extended stuurt Gemini-grounding, niet AI Overviews —
     * die volgt de gewone Googlebot-regels hierboven.
     *
     * @var list<string>
     */
    private const AI_CRAWLERS = [
        'OAI-SearchBot',
        'ChatGPT-User',
        'Claude-User',
        'Claude-SearchBot',
        'PerplexityBot',
        'Perplexity-User',
    ];

    public function __invoke(): Response
    {
        $lines = app()->isProduction()
            ? $this->productionRules()
            : ['# Niet-productie — niet indexeren.', 'User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * @return list<string>
     */
    private function productionRules(): array
    {
        $disallow = array_map(
            fn (string $path): string => 'Disallow: '.$path,
            PublicPages::DISALLOWED_PATHS,
        );

        return [
            '# Publiek: marketing, partner-showcase en juridische pagina\'s.',
            '# Afgeschermd: consumer-API, admin, webhook-ontvangst en OAuth-landing.',
            '',
            'User-agent: *',
            ...$disallow,
            '',
            '# AI-crawlers die naar de bron linken — zelfde surface, expliciet',
            '# toegestaan. Trainings-crawlers worden aan de Cloudflare-rand geweerd.',
            ...array_map(fn (string $agent): string => 'User-agent: '.$agent, self::AI_CRAWLERS),
            ...$disallow,
            '',
            'Sitemap: '.route('sitemap'),
        ];
    }
}
