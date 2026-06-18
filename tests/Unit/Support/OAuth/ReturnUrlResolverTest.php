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
}
