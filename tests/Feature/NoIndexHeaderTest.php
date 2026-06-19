<?php

namespace Tests\Feature;

use Tests\TestCase;

class NoIndexHeaderTest extends TestCase
{
    public function test_up_endpoint_has_x_robots_tag_header(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }

    public function test_home_endpoint_is_indexable(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_robots_txt_allows_only_home_and_partners(): void
    {
        $robotsTxt = public_path('robots.txt');

        $this->assertFileExists($robotsTxt);
        $contents = file_get_contents($robotsTxt);

        $this->assertStringContainsString('User-agent: *', $contents);
        $this->assertStringContainsString('Allow: /$', $contents);
        $this->assertStringContainsString('Allow: /partners', $contents);
        $this->assertStringContainsString('Disallow: /', $contents);
    }
}
