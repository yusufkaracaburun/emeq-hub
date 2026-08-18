<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\Connect;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Mollie · Connect', description: 'Mollie Connect partner-resources (onboarding, organizations, profiles, permissions, client-links).', weight: 60)]
class OrganizationsController extends AbstractMollieConnectPassThroughController
{
    public function me(Request $request): Response
    {
        return $this->handle($request, '/v2/organizations/me', function (Request $r) {
            $organization = $this->dispatchMollieCall(
                fn () => $this->client($r)->organizations->get('me'),
            );

            return $this->resourceToArray($organization);
        });
    }

    public function show(Request $request, string $id): Response
    {
        return $this->handle($request, '/v2/organizations/{id}', function (Request $r) use ($id) {
            $organization = $this->dispatchMollieCall(
                fn () => $this->client($r)->organizations->get($id),
            );

            return $this->resourceToArray($organization);
        });
    }
}
