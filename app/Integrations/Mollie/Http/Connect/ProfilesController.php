<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\Connect;

use App\Integrations\Mollie\Http\Requests\Connect\CreateProfileRequest;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Mollie\Api\Resources\BaseCollection;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Mollie · Connect', description: 'Mollie Connect partner-resources (onboarding, organizations, profiles, permissions, client-links).', weight: 60)]
class ProfilesController extends AbstractMollieConnectPassThroughController
{
    public function index(Request $request): Response
    {
        return $this->handle($request, '/v2/profiles', function (Request $r) {
            $from = $r->query('from');
            $limit = $r->query('limit');

            $page = $this->dispatchMollieCall(
                fn () => $this->client($r)->profiles->page(
                    is_string($from) ? $from : null,
                    is_numeric($limit) ? (int) $limit : null,
                ),
            );

            return $page instanceof BaseCollection
                ? $this->collectionToArray($page)
                : $this->resourceToArray($page);
        });
    }

    public function store(CreateProfileRequest $request): Response
    {
        return $this->handle($request, '/v2/profiles', function (Request $r) {
            /** @var CreateProfileRequest $r */
            $profile = $this->dispatchMollieCall(
                fn () => $this->client($r)->profiles->create($r->validated()),
            );

            return ['status' => 201, 'body' => $this->resourceToArray($profile)];
        });
    }

    public function show(Request $request, string $id): Response
    {
        return $this->handle($request, '/v2/profiles/{id}', function (Request $r) use ($id) {
            $profile = $this->dispatchMollieCall(
                fn () => $this->client($r)->profiles->get($id),
            );

            return $this->resourceToArray($profile);
        });
    }
}
