<?php

declare(strict_types=1);

namespace Tests\Unit\Support\OAuth;

use App\Models\Consumer;
use App\Support\OAuth\ReturnUrlResolver;
use Tests\TestCase;

class ReturnUrlResolverTest extends TestCase
{
    private ReturnUrlResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new ReturnUrlResolver;
    }

    public function test_accepts_requested_url_with_same_host_as_app_url(): void
    {
        $consumer = new Consumer(['app_url' => 'https://consumer.test']);

        $this->assertSame(
            'https://consumer.test/integraties/klaar',
            $this->resolver->resolve($consumer, 'https://consumer.test/integraties/klaar'),
        );
    }

    public function test_rejects_foreign_host_and_falls_back_to_app_url(): void
    {
        $consumer = new Consumer(['app_url' => 'https://consumer.test']);

        $this->assertSame(
            'https://consumer.test',
            $this->resolver->resolve($consumer, 'https://evil.test/steal'),
        );
    }

    public function test_falls_back_to_app_url_when_no_return_url_requested(): void
    {
        $consumer = new Consumer(['app_url' => 'https://consumer.test']);

        $this->assertSame('https://consumer.test', $this->resolver->resolve($consumer, null));
    }

    public function test_rejects_relative_url_without_host(): void
    {
        $consumer = new Consumer(['app_url' => 'https://consumer.test']);

        $this->assertSame('https://consumer.test', $this->resolver->resolve($consumer, '/relative/path'));
    }

    public function test_returns_null_when_consumer_has_no_app_url(): void
    {
        $consumer = new Consumer;

        $this->assertNull($this->resolver->resolve($consumer, 'https://evil.test'));
        $this->assertNull($this->resolver->resolve($consumer, null));
    }

    public function test_accepts_tenant_subdomain_of_same_base_domain(): void
    {
        // Consumer-app op admin.emeq.nl, tenant-SPA op bob.emeq.nl → toegestaan,
        // anders landt de tenant na connect op het verkeerde subdomein.
        $consumer = new Consumer(['app_url' => 'https://admin.emeq.nl']);

        $this->assertSame(
            'https://bob.emeq.nl/integraties/klaar',
            $this->resolver->resolve($consumer, 'https://bob.emeq.nl/integraties/klaar'),
        );
        $this->assertSame(
            'https://emeq.nl/x',
            $this->resolver->resolve($consumer, 'https://emeq.nl/x'),
        );
    }

    public function test_rejects_other_consumer_domain(): void
    {
        $consumer = new Consumer(['app_url' => 'https://admin.emeq.nl']);

        $this->assertSame(
            'https://admin.emeq.nl',
            $this->resolver->resolve($consumer, 'https://admin.planny.nl/steal'),
        );
    }

    public function test_rejects_lookalike_suffix_host(): void
    {
        $consumer = new Consumer(['app_url' => 'https://admin.emeq.nl']);

        // emeq.nl.evil.com en xemeq.nl mogen niet matchen op het basisdomein.
        $this->assertSame(
            'https://admin.emeq.nl',
            $this->resolver->resolve($consumer, 'https://emeq.nl.evil.com/steal'),
        );
        $this->assertSame(
            'https://admin.emeq.nl',
            $this->resolver->resolve($consumer, 'https://xemeq.nl/steal'),
        );
    }

    public function test_uses_browser_origin_when_no_return_url_and_origin_matches_domain(): void
    {
        // Consumer stuurt niets mee; de browser-Origin (bob.emeq.nl) drijft de
        // terugkeer — zo werkt het zonder code-wijziging bij de consumer.
        $consumer = new Consumer(['app_url' => 'https://admin.emeq.nl']);

        $this->assertSame(
            'https://bob.emeq.nl',
            $this->resolver->resolve($consumer, null, 'https://bob.emeq.nl'),
        );
    }

    public function test_explicit_return_url_wins_over_origin(): void
    {
        $consumer = new Consumer(['app_url' => 'https://admin.emeq.nl']);

        $this->assertSame(
            'https://bob.emeq.nl/instellingen',
            $this->resolver->resolve($consumer, 'https://bob.emeq.nl/instellingen', 'https://tbi.emeq.nl'),
        );
    }

    public function test_foreign_origin_is_ignored_and_falls_back_to_app_url(): void
    {
        $consumer = new Consumer(['app_url' => 'https://admin.emeq.nl']);

        $this->assertSame(
            'https://admin.emeq.nl',
            $this->resolver->resolve($consumer, null, 'https://evil.example'),
        );
    }
}
