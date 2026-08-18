<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\OAuth;

use App\Integrations\OAuth\ReturnUrlResolver;
use App\Models\Consumer;
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

        $this->assertSame(
            'https://admin.emeq.nl',
            $this->resolver->resolve($consumer, 'https://emeq.nl.evil.com/steal'),
        );
        $this->assertSame(
            'https://admin.emeq.nl',
            $this->resolver->resolve($consumer, 'https://xemeq.nl/steal'),
        );
    }

    public function test_rejects_sibling_on_multi_part_public_suffix(): void
    {
        $consumer = new Consumer(['app_url' => 'https://acme.co.uk']);

        $this->assertSame(
            'https://acme.co.uk',
            $this->resolver->resolve($consumer, 'https://evil.co.uk/steal'),
        );
    }

    public function test_accepts_real_subdomain_on_multi_part_public_suffix(): void
    {
        $consumer = new Consumer(['app_url' => 'https://acme.co.uk']);

        $this->assertSame(
            'https://app.acme.co.uk/integraties/klaar',
            $this->resolver->resolve($consumer, 'https://app.acme.co.uk/integraties/klaar'),
        );
    }

    public function test_uses_browser_origin_when_no_return_url_and_origin_matches_domain(): void
    {
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

    public function test_handoff_returns_null_instead_of_falling_back_to_app_url(): void
    {
        $consumer = new Consumer(['app_url' => 'https://emeq.nl']);

        $this->assertNull(
            $this->resolver->resolveHandoff($consumer, 'https://demo.emeq:8890/configuration/integraties'),
        );
        $this->assertNull(
            $this->resolver->resolveHandoff($consumer, null, 'https://evil.example'),
        );
        $this->assertNull(
            $this->resolver->resolveHandoff($consumer, null),
        );
    }

    public function test_handoff_still_accepts_matching_return_url_and_origin(): void
    {
        $consumer = new Consumer(['app_url' => 'https://admin.emeq.nl']);

        $this->assertSame(
            'https://bob.emeq.nl/instellingen',
            $this->resolver->resolveHandoff($consumer, 'https://bob.emeq.nl/instellingen'),
        );
        $this->assertSame(
            'https://bob.emeq.nl',
            $this->resolver->resolveHandoff($consumer, null, 'https://bob.emeq.nl'),
        );
    }
}
