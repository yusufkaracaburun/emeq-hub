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

    public function test_root_endpoint_has_x_robots_tag_header(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }

    public function test_robots_txt_disallows_all(): void
    {
        $robotsTxt = public_path('robots.txt');

        $this->assertFileExists($robotsTxt);
        $this->assertStringContainsString('User-agent: *', file_get_contents($robotsTxt));
        $this->assertStringContainsString('Disallow: /', file_get_contents($robotsTxt));
    }
}
