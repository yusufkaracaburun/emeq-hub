<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * D-12: alle integration-tests die echte Mollie test-mode API hitten erven
 * van deze base-class. Skipt automatisch als de Mollie test-key niet (of niet
 * met `test_`-prefix) gezet is — voorkomt CI-failures op feature-branches
 * zonder secrets.
 *
 * Het #[Group('integration')]-attribute zorgt dat:
 *  - phpunit.xml's <groups><exclude>-block deze tests OVERSLAAT in de
 *    standaard `php artisan test`-suite.
 *  - phpunit.integration.xml's <groups><include>-block deze tests EXCLUSIEF
 *    runt via `composer test:integration`.
 */
#[Group('integration')]
abstract class IntegrationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $key = env('CASHIER_MOLLIE_KEY') ?: env('MOLLIE_KEY');
        if (! is_string($key) || $key === '' || ! str_starts_with($key, 'test_') || $key === 'test_xxx') {
            $this->markTestSkipped(
                'Integration tests require CASHIER_MOLLIE_KEY (test_-prefix, niet de '
                .'`test_xxx`-placeholder uit .env.example). Run `composer test:integration` '
                .'apart, niet als onderdeel van `php artisan test`.'
            );
        }

        config([
            'cashier.key' => $key,
            'mollie.key' => $key,
            'services.cashier.webhook_secret' => env('CASHIER_WEBHOOK_SECRET') ?: 'whsec_integration_test',
        ]);
    }
}
