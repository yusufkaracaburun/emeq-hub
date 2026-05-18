<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mollie\Connect;

use App\Http\Requests\Api\V1\Mollie\Connect\CreateClientLinkRequest;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Mollie · Connect', description: 'Mollie Connect partner-resources (onboarding, organizations, profiles, permissions, client-links).', weight: 60)]
class ClientLinksController extends AbstractMollieConnectPassThroughController
{
    public function store(CreateClientLinkRequest $request): Response
    {
        return $this->handle($request, '/v2/client-links', function (Request $r) {
            /** @var CreateClientLinkRequest $r */
            $clientLink = $this->dispatchMollieCall(
                fn () => $this->client($r)->clientLinks->create($r->validated()),
            );

            return ['status' => 201, 'body' => $this->resourceToArray($clientLink)];
        });
    }
}
