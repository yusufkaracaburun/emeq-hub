<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PublicPages;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __construct(private readonly PublicPages $pages) {}

    public function __invoke(): Response
    {
        $entries = array_map(
            fn (array $url): string => sprintf(
                '    <url><loc>%s</loc><changefreq>%s</changefreq><priority>%s</priority></url>',
                htmlspecialchars($url['loc'], ENT_XML1),
                $url['changefreq'],
                $url['priority'],
            ),
            $this->pages->sitemapUrls(),
        );

        $xml = implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            ...$entries,
            '</urlset>',
        ]);

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
