<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Het root-document (blade-head) draagt de premium branding/SEO-meta: favicon,
 * theme-color, manifest en Open-Graph/Twitter-card met og-image. De assets zelf
 * worden door de webserver geserveerd (niet de router), dus die checken we op disk.
 */
class HeadMetaTest extends TestCase
{
    public function test_root_document_has_branding_and_og_meta(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        foreach ([
            'name="theme-color"',
            'rel="manifest"',
            'href="/favicon.svg"',
            'apple-touch-icon',
            'property="og:image"',
            '/og-image.png',
            'name="twitter:card"',
        ] as $needle) {
            $response->assertSee($needle, false);
        }
    }

    public function test_branding_assets_exist_on_disk(): void
    {
        foreach (['favicon.svg', 'favicon.ico', 'favicon-32.png', 'apple-touch-icon.png', 'og-image.png', 'site.webmanifest'] as $asset) {
            $path = public_path($asset);
            $this->assertFileExists($path);
            $this->assertGreaterThan(0, filesize($path), "{$asset} is leeg");
        }
    }
}
