<?php

declare(strict_types=1);

namespace App\Support;

class PublicPages
{
    /** @var list<string> */
    public const INDEXABLE_ROUTES = [
        'home',
        'partners.*',
        'privacy',
        'terms',
        'processor-agreement',
        'support',
        'koppelen',
        'demo',
    ];

    /** @var list<string> */
    public const DISALLOWED_PATHS = [
        '/admin',
        '/v1/',
        '/webhooks/',
        '/cashier/',
        '/oauth/',
        '/exact/',
        '/dev/',
        '/docs/',
        '/horizon',
        '/livewire/',
        '/storage/',
        '/up',
    ];

    public function __construct(private readonly ProviderShowcase $showcase) {}

    /** @return list<array{loc:string,priority:string,changefreq:string}> */
    public function sitemapUrls(): array
    {
        $urls = [
            ['loc' => route('home'), 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['loc' => route('partners.index'), 'priority' => '0.9', 'changefreq' => 'weekly'],
        ];

        foreach ($this->showcase->summaries() as $provider) {
            $urls[] = [
                'loc' => route('partners.show', $provider['key']),
                'priority' => '0.8',
                'changefreq' => 'monthly',
            ];
        }

        $urls[] = ['loc' => route('koppelen'), 'priority' => '0.7', 'changefreq' => 'monthly'];
        $urls[] = ['loc' => route('demo'), 'priority' => '0.7', 'changefreq' => 'monthly'];
        $urls[] = ['loc' => route('support'), 'priority' => '0.5', 'changefreq' => 'monthly'];

        foreach (['privacy', 'terms', 'processor-agreement'] as $name) {
            $urls[] = ['loc' => route($name), 'priority' => '0.3', 'changefreq' => 'yearly'];
        }

        return $urls;
    }
}
