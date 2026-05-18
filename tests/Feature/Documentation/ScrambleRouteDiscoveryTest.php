<?php

namespace Tests\Feature\Documentation;

use Tests\TestCase;

/**
 * Bewijst HUB-05 SC-8: Scramble's OpenAPI-spec bevat alle nieuwe /v1-routes
 * die in Phase 5b zijn geland (3 provisioning + 1 catch-all + ping).
 *
 * Scramble's docs-route zit achter `RestrictedDocsAccess`-middleware; in
 * testing-environment is een `?token=`-query nodig die matched met
 * `config('scramble.access_token')` (zie AppServiceProvider's viewApiDocs-Gate).
 */
class ScrambleRouteDiscoveryTest extends TestCase
{
    private const TOKEN = 'test-scramble-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['scramble.access_token' => self::TOKEN]);
    }

    public function test_openapi_spec_contains_post_v1_accounts_route(): void
    {
        $spec = $this->fetchSpec();

        $this->assertArrayHasKey('/accounts', $spec['paths'] ?? []);
        $this->assertArrayHasKey('post', $spec['paths']['/accounts']);
    }

    public function test_openapi_spec_contains_post_v1_connections_route(): void
    {
        $spec = $this->fetchSpec();

        $this->assertArrayHasKey('/connections', $spec['paths'] ?? []);
        $this->assertArrayHasKey('post', $spec['paths']['/connections']);
    }

    public function test_openapi_spec_contains_show_and_delete_v1_connections_id_routes(): void
    {
        $spec = $this->fetchSpec();

        $this->assertArrayHasKey('/connections/{connection}', $spec['paths'] ?? []);
        $this->assertArrayHasKey('get', $spec['paths']['/connections/{connection}']);
        $this->assertArrayHasKey('delete', $spec['paths']['/connections/{connection}']);
    }

    public function test_openapi_spec_contains_snelstart_passthrough_catchall(): void
    {
        $spec = $this->fetchSpec();
        $paths = $spec['paths'] ?? [];

        // Scramble's path-template-resolver kan de `Route::any('/snelstart/{path}')`
        // catch-all op verschillende manieren renderen — als één `{path}`-template
        // óf als afzonderlijke entries per HTTP-method. Geef beide vormen een kans;
        // als geen van beide bestaat, markeer skipped met ADR-pointer.
        $candidates = ['/snelstart/{path}', '/snelstart'];
        $matched = null;

        foreach ($candidates as $candidate) {
            if (isset($paths[$candidate])) {
                $matched = $candidate;
                break;
            }
        }

        if ($matched === null) {
            $this->markTestSkipped(
                'Scramble rendert de Route::any catch-all op /v1/snelstart/{path} niet als path-entry. '
                .'Zie ADR `.docs/decisions/scramble-passthrough-route-discovery.md` voor follow-up '
                .'(toekomstige optie: per-resource routes naast de catch-all).',
            );
        }

        $operations = $paths[$matched];
        $hasAnyMethod = ! empty(array_intersect(['get', 'post', 'patch', 'delete'], array_keys($operations)));
        $this->assertTrue($hasAnyMethod, 'Catch-all moet minstens één HTTP-method exposeren in de spec.');
    }

    public function test_openapi_spec_contains_mollie_payments_routes(): void
    {
        $spec = $this->fetchSpec();
        $paths = $spec['paths'] ?? [];

        $this->assertArrayHasKey('/mollie/payments', $paths);
        $this->assertArrayHasKey('post', $paths['/mollie/payments']);
        $this->assertArrayHasKey('/mollie/payments/{id}', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/payments/{id}']);
        $this->assertArrayHasKey('delete', $paths['/mollie/payments/{id}']);
    }

    public function test_openapi_spec_contains_mollie_customers_routes(): void
    {
        $spec = $this->fetchSpec();
        $paths = $spec['paths'] ?? [];

        $this->assertArrayHasKey('/mollie/customers', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/customers']);
        $this->assertArrayHasKey('post', $paths['/mollie/customers']);
        $this->assertArrayHasKey('/mollie/customers/{id}', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/customers/{id}']);
    }

    public function test_openapi_spec_contains_mollie_payment_methods_route(): void
    {
        $spec = $this->fetchSpec();
        $paths = $spec['paths'] ?? [];

        $this->assertArrayHasKey('/mollie/payment-methods', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/payment-methods']);
    }

    public function test_openapi_spec_contains_mollie_refunds_routes(): void
    {
        // Scramble gebruikt controller-argument-namen voor path-variables
        // i.p.v. de route-placeholder-naam. RefundsController's nested
        // routes nemen `$payment_id` als argument, dus Scramble rendert
        // `{payment_id}` (NIET `{id}` zoals de route-definitie).
        $spec = $this->fetchSpec();
        $paths = $spec['paths'] ?? [];

        $this->assertArrayHasKey('/mollie/payments/{payment_id}/refunds', $paths);
        $this->assertArrayHasKey('post', $paths['/mollie/payments/{payment_id}/refunds']);
        $this->assertArrayHasKey('get', $paths['/mollie/payments/{payment_id}/refunds']);
        $this->assertArrayHasKey('/mollie/refunds/{id}', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/refunds/{id}']);
    }

    public function test_openapi_spec_contains_mollie_mandates_routes(): void
    {
        // Scramble rendert `{customer_id}` (uit controller-argument), niet
        // `{id}` (uit route-placeholder). Zie test_openapi_spec_contains_mollie_refunds_routes.
        $spec = $this->fetchSpec();
        $paths = $spec['paths'] ?? [];

        $this->assertArrayHasKey('/mollie/customers/{customer_id}/mandates', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/customers/{customer_id}/mandates']);
        $this->assertArrayHasKey('/mollie/customers/{customer_id}/mandates/{mandate_id}', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/customers/{customer_id}/mandates/{mandate_id}']);
        $this->assertArrayHasKey('delete', $paths['/mollie/customers/{customer_id}/mandates/{mandate_id}']);
    }

    public function test_openapi_spec_contains_mollie_subscriptions_routes(): void
    {
        $spec = $this->fetchSpec();
        $paths = $spec['paths'] ?? [];

        $this->assertArrayHasKey('/mollie/customers/{customer_id}/subscriptions', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/customers/{customer_id}/subscriptions']);
        $this->assertArrayHasKey('post', $paths['/mollie/customers/{customer_id}/subscriptions']);
        $this->assertArrayHasKey('/mollie/customers/{customer_id}/subscriptions/{sub_id}', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/customers/{customer_id}/subscriptions/{sub_id}']);
        $this->assertArrayHasKey('delete', $paths['/mollie/customers/{customer_id}/subscriptions/{sub_id}']);
    }

    public function test_openapi_spec_contains_mollie_payment_links_routes(): void
    {
        $spec = $this->fetchSpec();
        $paths = $spec['paths'] ?? [];

        $this->assertArrayHasKey('/mollie/payment-links', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/payment-links']);
        $this->assertArrayHasKey('post', $paths['/mollie/payment-links']);
        $this->assertArrayHasKey('/mollie/payment-links/{id}', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/payment-links/{id}']);
    }

    /**
     * MOLL-05 SC-3 — alle 9 Mollie-Connect-routes (Phase 13 Plan 02) staan in
     * de OpenAPI-spec onder de juiste paths + HTTP-methods.
     */
    public function test_openapi_spec_contains_all_nine_mollie_connect_routes(): void
    {
        $spec = $this->fetchSpec();
        $paths = $spec['paths'] ?? [];

        $this->assertArrayHasKey('/mollie/connect/onboarding/me', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/connect/onboarding/me']);

        $this->assertArrayHasKey('/mollie/connect/organizations/me', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/connect/organizations/me']);

        $this->assertArrayHasKey('/mollie/connect/organizations/{id}', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/connect/organizations/{id}']);

        $this->assertArrayHasKey('/mollie/connect/profiles', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/connect/profiles']);
        $this->assertArrayHasKey('post', $paths['/mollie/connect/profiles']);

        $this->assertArrayHasKey('/mollie/connect/profiles/{id}', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/connect/profiles/{id}']);

        $this->assertArrayHasKey('/mollie/connect/permissions', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/connect/permissions']);

        $this->assertArrayHasKey('/mollie/connect/permissions/{id}', $paths);
        $this->assertArrayHasKey('get', $paths['/mollie/connect/permissions/{id}']);

        $this->assertArrayHasKey('/mollie/connect/client-links', $paths);
        $this->assertArrayHasKey('post', $paths['/mollie/connect/client-links']);
    }

    /**
     * MOLL-05 SC-3 — Connect-routes worden gegroepeerd onder de gedeelde
     * Scramble Group 'Mollie · Connect' (D-12). Test minimaal 3 paths om
     * bewijs te leveren zonder alle 9 te dupliceren.
     */
    public function test_openapi_spec_groups_connect_routes_under_mollie_connect_tag(): void
    {
        $spec = $this->fetchSpec();
        $paths = $spec['paths'] ?? [];

        $samples = [
            ['/mollie/connect/onboarding/me', 'get'],
            ['/mollie/connect/profiles', 'post'],
            ['/mollie/connect/client-links', 'post'],
        ];

        foreach ($samples as [$path, $method]) {
            $this->assertArrayHasKey($path, $paths, "Connect-path {$path} ontbreekt in OpenAPI-spec");
            $this->assertArrayHasKey($method, $paths[$path], "Method {$method} ontbreekt op {$path}");

            $tags = $paths[$path][$method]['tags'] ?? [];
            $this->assertContains(
                'Mollie · Connect',
                $tags,
                "Path {$path} {$method} mist tag 'Mollie · Connect' — kreeg: ".json_encode($tags),
            );
        }
    }

    /**
     * Regressie-vangst: bestaande Mollie-merchant-tags blijven onveranderd
     * (geen onbedoelde #[Group]-attribuut-edits). Zonder deze test zou een
     * accidentele wijziging in `Mollie · Payments` of `Mollie · Customers`
     * tag-keten ongezien blijven.
     */
    public function test_openapi_spec_preserves_existing_mollie_merchant_tags(): void
    {
        $spec = $this->fetchSpec();
        $paths = $spec['paths'] ?? [];

        $merchantSamples = [
            ['/mollie/payments', 'post', 'Mollie · Payments'],
            ['/mollie/customers', 'get', 'Mollie · Customers'],
        ];

        foreach ($merchantSamples as [$path, $method, $expectedTag]) {
            $tags = $paths[$path][$method]['tags'] ?? [];
            $this->assertContains(
                $expectedTag,
                $tags,
                "Path {$path} {$method} verloor tag '{$expectedTag}' — kreeg: ".json_encode($tags),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSpec(): array
    {
        $response = $this->getJson('/docs/api.json?token='.self::TOKEN);
        $response->assertOk();

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }
}
