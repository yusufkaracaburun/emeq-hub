<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PublicPages;
use Illuminate\Http\Response;

class RobotsController extends Controller
{
    /** @var list<string> */
    private const AI_CRAWLERS = [
        'OAI-SearchBot',
        'ChatGPT-User',
        'Claude-User',
        'Claude-SearchBot',
        'PerplexityBot',
        'Perplexity-User',
    ];

    /** @var list<string> */
    private const TRAINING_CRAWLERS = [
        'GPTBot',
        'ClaudeBot',
        'Google-Extended',
        'Applebot-Extended',
        'meta-externalagent',
        'Amazonbot',
        'Bytespider',
        'CCBot',
    ];

    public function __invoke(): Response
    {
        $lines = app()->isProduction()
            ? $this->productionRules()
            : ['# Niet-productie, niet indexeren.', 'User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** @return list<string> */
    private function productionRules(): array
    {
        $disallow = array_map(
            fn (string $path): string => 'Disallow: '.$path,
            PublicPages::DISALLOWED_PATHS,
        );

        return [
            '# Publiek: marketing, partner-showcase en juridische pagina\'s.',
            '# Afgeschermd: consumer-API, admin, webhook-ontvangst en OAuth-landing.',
            '#',
            '# Content-Signal legt vast waarvoor deze content gebruikt mag worden:',
            '# indexeren en citeren ja, modeltraining nee. Dat is een uitdrukkelijk',
            '# voorbehoud van rechten onder artikel 4 van EU-richtlijn 2019/790.',
            '',
            'User-agent: *',
            'Content-Signal: search=yes,ai-train=no,use=reference',
            ...$disallow,
            '',
            '# AI-crawlers die een antwoord samenstellen en naar de bron linken:',
            '# zelfde surface als iedereen.',
            ...array_map(fn (string $agent): string => 'User-agent: '.$agent, self::AI_CRAWLERS),
            ...$disallow,
            '',
            '# Crawlers die uitsluitend trainingsdata verzamelen.',
            ...array_map(fn (string $agent): string => 'User-agent: '.$agent, self::TRAINING_CRAWLERS),
            'Disallow: /',
            '',
            'Sitemap: '.route('sitemap'),
        ];
    }
}
