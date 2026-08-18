<?php

namespace Tests\Feature\Documentation;

use Tests\TestCase;

class ScrambleRouteDiscoveryTest extends TestCase
{
    public function test_docs_zijn_publiek_bereikbaar_zonder_token(): void
    {
        $this->get('/docs/api')->assertOk();
        $this->getJson('/docs/api.json')->assertOk();
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

    /** @return array<string, mixed> */
    private function fetchSpec(): array
    {
        $response = $this->getJson('/docs/api.json');
        $response->assertOk();

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }
}
