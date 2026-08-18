<?php

declare(strict_types=1);

namespace Tests\Integration\AccountSubscriptions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('integration')]
abstract class AccountSubscriptionIntegrationTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $mollieConnectAccessToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $token = env('MOLLIE_CONNECT_TEST_ACCESS_TOKEN');
        if (! is_string($token) || $token === '' || ! str_starts_with($token, 'access_') || $token === 'access_xxx') {
            $this->markTestSkipped(
                'Account-subscription integration tests vereisen een Connect-test-token in '
                .'MOLLIE_CONNECT_TEST_ACCESS_TOKEN (access_-prefix, niet de `access_xxx`-placeholder '
                .'uit .env.example). Run `composer test:integration` apart, niet als onderdeel van '
                .'`php artisan test`.'
            );
        }

        $this->mollieConnectAccessToken = $token;
    }
}
