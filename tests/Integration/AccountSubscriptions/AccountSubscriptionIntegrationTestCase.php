<?php

declare(strict_types=1);

namespace Tests\Integration\AccountSubscriptions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Phase 7 / D-26: alle integration-tests voor /v1/account-subscriptions die
 * de echte Mollie test-mode API hitten via een Connect-`access_token` erven
 * van deze base-class. Skipt automatisch als `MOLLIE_CONNECT_TEST_ACCESS_TOKEN`
 * niet (of niet met `access_`-prefix) gezet is — voorkomt CI-failures op
 * feature-branches zonder secrets.
 *
 * Verschil met `Tests\Integration\IntegrationTestCase` (Phase 6): die guard
 * vereist `CASHIER_MOLLIE_KEY` (Emeq's eigen Mollie API-key, test_-prefix);
 * Phase 7 testet use-case B via Connect-OAuth, dus we hebben een Personal
 * Access Token nodig dat een merchant-account representeert (access_-prefix,
 * verkregen via Mollie Dashboard → Settings → Developers → Personal access
 * tokens, test-mode).
 *
 * Het #[Group('integration')]-attribute zorgt dat:
 *  - phpunit.xml's <groups><exclude>-block deze tests OVERSLAAT in de
 *    standaard `php artisan test`-suite.
 *  - phpunit.integration.xml's <groups><include>-block deze tests EXCLUSIEF
 *    runt via `composer test:integration`.
 */
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
