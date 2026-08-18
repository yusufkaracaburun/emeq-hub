<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie\Connect;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class ConnectRouteRegistrationTest extends TestCase
{
    /** @return list<array{method: string, uri: string}> */
    private function expectedRoutes(): array
    {
        return [
            ['method' => 'GET', 'uri' => 'v1/mollie/connect/onboarding/me'],
            ['method' => 'GET', 'uri' => 'v1/mollie/connect/organizations/me'],
            ['method' => 'GET', 'uri' => 'v1/mollie/connect/organizations/{id}'],
            ['method' => 'GET', 'uri' => 'v1/mollie/connect/profiles'],
            ['method' => 'POST', 'uri' => 'v1/mollie/connect/profiles'],
            ['method' => 'GET', 'uri' => 'v1/mollie/connect/profiles/{id}'],
            ['method' => 'GET', 'uri' => 'v1/mollie/connect/permissions'],
            ['method' => 'GET', 'uri' => 'v1/mollie/connect/permissions/{id}'],
            ['method' => 'POST', 'uri' => 'v1/mollie/connect/client-links'],
        ];
    }

    /** @return list<Route> */
    private function connectRoutes(): array
    {
        $routes = [];
        foreach (RouteFacade::getRoutes() as $route) {
            /** @var Route $route */
            if (str_starts_with($route->uri(), 'v1/mollie/connect')) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    public function test_all_nine_connect_routes_are_registered_under_v1_mollie_connect_prefix(): void
    {
        $expected = $this->expectedRoutes();
        $actual = [];

        foreach ($this->connectRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $actual[] = ['method' => $method, 'uri' => $route->uri()];
                }
            }
        }

        foreach ($expected as $tuple) {
            $this->assertContains(
                $tuple,
                $actual,
                "Missing Connect-route: {$tuple['method']} {$tuple['uri']}",
            );
        }
    }

    public function test_connect_routes_have_required_middleware_and_no_resolve_mollie_account(): void
    {
        foreach ($this->connectRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains(
                'auth:sanctum',
                $middleware,
                "Route {$route->uri()} mist auth:sanctum-middleware",
            );
            $this->assertContains(
                'feature.provider:mollie',
                $middleware,
                "Route {$route->uri()} mist feature.provider:mollie-middleware",
            );
            $this->assertNotContains(
                'resolve.mollie.account',
                $middleware,
                "Route {$route->uri()} mag GEEN resolve.mollie.account-middleware hebben (D-07)",
            );
        }
    }

    public function test_connect_route_names_follow_api_mollie_connect_namespace(): void
    {
        foreach ($this->connectRoutes() as $route) {
            $name = (string) $route->getName();

            $this->assertNotSame(
                '',
                $name,
                "Route {$route->uri()} mist een name",
            );
            $this->assertStringStartsWith(
                'api.mollie.connect.',
                $name,
                "Route {$route->uri()} name '{$name}' moet starten met 'api.mollie.connect.'",
            );
        }
    }
}
