<?php

namespace Tests\Feature\Integrations\Mollie\Http\AccountSubscriptions;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

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

        $storeMiddleware = Route::getRoutes()->getByName('api.account-subscriptions.store')->gatherMiddleware();
        $this->assertContains('auth:sanctum', $storeMiddleware, 'store mist auth:sanctum');
        $this->assertContains('ability:mollie:write,*', $storeMiddleware, 'store mist ability:mollie:write,*');

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
