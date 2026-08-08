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
     * AI-crawlers die de publieke pagina's expliciet mógen lezen. Zonder deze
     * regels gedragen sommige zich alsof er geen toestemming is; en het maakt
     * de keuze reviewbaar in plaats van impliciet.
     *
     * Let op: Google-Extended stuurt Gemini-grounding, niet AI Overviews —
     * die volgt de gewone Googlebot-regels hierboven.
     *
     * @var list<string>
     */
    private const AI_CRAWLERS = [
        'GPTBot',
        'OAI-SearchBot',
        'ChatGPT-User',
        'ClaudeBot',
        'Claude-User',
        'Claude-SearchBot',
        'PerplexityBot',
        'Perplexity-User',
        'Google-Extended',
        'Applebot-Extended',
        'meta-externalagent',
        'Amazonbot',
        'CCBot',
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
            '# AI-/LLM-crawlers — zelfde surface, expliciet toegestaan.',
            ...array_map(fn (string $agent): string => 'User-agent: '.$agent, self::AI_CRAWLERS),
            ...$disallow,
            '',
            'Sitemap: '.route('sitemap'),
        ];
    }
}
