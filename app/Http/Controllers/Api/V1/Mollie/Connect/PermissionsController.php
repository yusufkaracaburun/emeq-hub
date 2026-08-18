<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mollie\Connect;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Mollie · Connect', description: 'Mollie Connect partner-resources (onboarding, organizations, profiles, permissions, client-links).', weight: 60)]
class PermissionsController extends AbstractMollieConnectPassThroughController
{
    public function index(Request $request): Response
    {
        return $this->handle($request, '/v2/permissions', function (Request $r) {
            $collection = $this->dispatchMollieCall(
                fn () => $this->client($r)->permissions->list(),
            );

            return $this->collectionToArray($collection);
        });
    }

    public function show(Request $request, string $id): Response
    {
        return $this->handle($request, '/v2/permissions/{id}', function (Request $r) use ($id) {
            $permission = $this->dispatchMollieCall(
                fn () => $this->client($r)->permissions->get($id),
            );

            return $this->resourceToArray($permission);
        });
    }
}
