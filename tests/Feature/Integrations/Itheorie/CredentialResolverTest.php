<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Itheorie;

use App\Integrations\Itheorie\HubItheorieCredentialResolver;
use App\Integrations\Itheorie\Settings\ItheorieSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class CredentialResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_omgeving_gebruikt_de_live_inlog(): void
    {
        $credentials = $this->resolverFor([
            'environment' => 'live',
            'username' => 'live-id',
            'password' => 'live-geheim',
            'base_url' => 'https://itheorie.nl/api/connect',
            'username_test' => 'test-id',
            'password_test' => 'test-geheim',
            'base_url_test' => 'https://test.itheorie.nl/api/connect',
        ])->resolve();

        $this->assertSame('live-id', $credentials->username);
        $this->assertSame('live-geheim', $credentials->password);
        $this->assertSame('https://itheorie.nl/api/connect', $credentials->baseUrl);
    }

    public function test_test_omgeving_gebruikt_de_test_inlog_en_de_test_url(): void
    {
        $credentials = $this->resolverFor([
            'environment' => 'test',
            'username' => 'live-id',
            'password' => 'live-geheim',
            'base_url' => 'https://itheorie.nl/api/connect',
            'username_test' => 'test-id',
            'password_test' => 'test-geheim',
            'base_url_test' => 'https://test.itheorie.nl/api/connect',
        ])->resolve();

        $this->assertSame('test-id', $credentials->username);
        $this->assertSame('test-geheim', $credentials->password);
        $this->assertSame('https://test.itheorie.nl/api/connect', $credentials->baseUrl);
    }

    public function test_test_omgeving_zonder_test_inlog_valt_niet_terug_op_live(): void
    {
        $resolver = $this->resolverFor([
            'environment' => 'test',
            'username' => 'live-id',
            'password' => 'live-geheim',
            'base_url' => 'https://itheorie.nl/api/connect',
            'username_test' => '',
            'password_test' => '',
            'base_url_test' => 'https://test.itheorie.nl/api/connect',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/test-omgeving/');

        $resolver->resolve();
    }

    public function test_het_resellernummer_geldt_voor_beide_omgevingen(): void
    {
        foreach (['live', 'test'] as $environment) {
            $credentials = $this->resolverFor([
                'environment' => $environment,
                'username' => 'live-id',
                'password' => 'live-geheim',
                'base_url' => 'https://itheorie.nl/api/connect',
                'username_test' => 'test-id',
                'password_test' => 'test-geheim',
                'base_url_test' => 'https://test.itheorie.nl/api/connect',
            ])->resolve();

            $this->assertSame('12345678', $credentials->reseller, "omgeving {$environment}");
        }
    }

    /** @param array<string, string> $values */
    private function resolverFor(array $values): HubItheorieCredentialResolver
    {
        $settings = app(ItheorieSettings::class);

        foreach ($values + ['reseller' => '12345678'] as $key => $value) {
            $settings->{$key} = $value;
        }

        return new HubItheorieCredentialResolver($settings);
    }
}
