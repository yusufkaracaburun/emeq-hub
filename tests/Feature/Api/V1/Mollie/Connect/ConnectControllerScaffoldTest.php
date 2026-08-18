<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie\Connect;

use App\Integrations\Mollie\Http\Connect\AbstractMollieConnectPassThroughController;
use App\Integrations\Mollie\Http\Connect\ClientLinksController;
use App\Integrations\Mollie\Http\Connect\OnboardingController;
use App\Integrations\Mollie\Http\Connect\OrganizationsController;
use App\Integrations\Mollie\Http\Connect\PermissionsController;
use App\Integrations\Mollie\Http\Connect\ProfilesController;
use App\Integrations\Mollie\Http\Requests\Connect\CreateClientLinkRequest;
use App\Integrations\Mollie\Http\Requests\Connect\CreateProfileRequest;
use ReflectionClass;
use Tests\TestCase;

class ConnectControllerScaffoldTest extends TestCase
{
    public function test_all_eight_connect_classes_autoload(): void
    {
        $classes = [
            AbstractMollieConnectPassThroughController::class,
            ClientLinksController::class,
            OnboardingController::class,
            OrganizationsController::class,
            PermissionsController::class,
            ProfilesController::class,
            CreateClientLinkRequest::class,
            CreateProfileRequest::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "Class {$class} should autoload");
        }
    }

    public function test_abstract_base_exposes_required_protected_methods(): void
    {
        $required = ['client', 'dispatchMollieCall', 'handle', 'resourceToArray', 'collectionToArray'];

        $reflection = new ReflectionClass(AbstractMollieConnectPassThroughController::class);

        foreach ($required as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "AbstractMollieConnectPassThroughController must declare {$method}()",
            );
            $this->assertTrue(
                $reflection->getMethod($method)->isProtected(),
                "{$method}() must be protected",
            );
        }
    }

    public function test_abstract_base_uses_container_resolution_for_mollie_client(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(AbstractMollieConnectPassThroughController::class))->getFileName(),
        );

        $this->assertStringContainsString(
            'app(MollieApiClient::class)',
            $source,
            'client() must resolve MollieApiClient via the container (test-injectable via $this->app->instance).',
        );
    }

    public function test_abstract_base_wraps_sdk_calls_via_mollie_exception_mapper(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(AbstractMollieConnectPassThroughController::class))->getFileName(),
        );

        $this->assertStringContainsString(
            'MollieExceptionMapper::map',
            $source,
            'dispatchMollieCall() must route raw Mollie\\Api\\Exceptions\\ApiException through MollieExceptionMapper::map().',
        );
    }

    public function test_abstract_base_audit_write_uses_partner_token_type(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(AbstractMollieConnectPassThroughController::class))->getFileName(),
        );

        $this->assertStringContainsString(
            "'token_type' => 'partner'",
            $source,
            'handle() must persist pass_through_calls rows with token_type=partner.',
        );
    }

    public function test_concrete_controllers_extend_the_connect_base(): void
    {
        foreach ([
            ClientLinksController::class,
            OnboardingController::class,
            OrganizationsController::class,
            PermissionsController::class,
            ProfilesController::class,
        ] as $controller) {
            $this->assertTrue(
                is_subclass_of($controller, AbstractMollieConnectPassThroughController::class),
                "{$controller} must extend AbstractMollieConnectPassThroughController",
            );
        }
    }
}
