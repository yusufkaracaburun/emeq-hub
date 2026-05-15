<?php

namespace Tests\Feature\Api\V1\AccountSubscriptions;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Nyquist §8c-marge — pin't middleware-stack + 401-bij-geen-PAT vóór de volledige
 * feature-suite (plan 07-06) tegen deze 6 routes aanslaat. Geen DB-writes nodig.
 */
class RouteRegistrationSmokeTest extends TestCase
{
    public function test_routes_are_registered_with_correct_middleware(): void
    {
        $expectedRoutes = [
            'api.account-subscriptions.store',
            'api.account-subscriptions.index',
            'api.account-subscriptions.show',
            'api.account-subscriptions.destroy',
            'api.account-subscriptions.pause',
            'api.account-subscriptions.resume',
        ];

        foreach ($expectedRoutes as $name) {
            $this->assertTrue(Route::has($name), "Route '{$name}' is not registered.");
        }

        // Write-route (store): vereist mollie:write,* via ability-alias.
        $storeMiddleware = Route::getRoutes()->getByName('api.account-subscriptions.store')->gatherMiddleware();
        $this->assertContains('auth:sanctum', $storeMiddleware, 'store mist auth:sanctum');
        $this->assertContains('ability:mollie:write,*', $storeMiddleware, 'store mist ability:mollie:write,*');

        // Read-route (index): vereist mollie:read,mollie:write,*.
        $indexMiddleware = Route::getRoutes()->getByName('api.account-subscriptions.index')->gatherMiddleware();
        $this->assertContains('auth:sanctum', $indexMiddleware, 'index mist auth:sanctum');
        $this->assertContains('ability:mollie:read,mollie:write,*', $indexMiddleware, 'index mist ability:mollie:read,mollie:write,*');
    }

    public function test_unauthenticated_post_returns_401(): void
    {
        $response = $this->postJson('/v1/account-subscriptions', []);

        $response->assertStatus(401);
    }
}
