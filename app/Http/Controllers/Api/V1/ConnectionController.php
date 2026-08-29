<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Connect\RevokeConnection;
use App\Enums\Provider;
use App\Http\Concerns\GuardsTokenAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreConnectionRequest;
use App\Http\Resources\Api\V1\ConnectionResource;
use App\Models\Account;
use App\Models\Connection;
use App\Sanctum\TokenAbilities;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Connections', description: 'OAuth-koppelingen tussen Account en provider (Mollie/Snelstart).', weight: 30)]
class ConnectionController extends Controller
{
    use GuardsTokenAbility;

    public function __construct(private readonly RevokeConnection $revokeConnection) {}

    public function store(StoreConnectionRequest $request): JsonResponse|ConnectionResource
    {
        $this->guardAbility($request, [
            TokenAbilities::INTEGRATIONS_MANAGE,
            TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            TokenAbilities::SNELSTART_WRITE,
            TokenAbilities::DATAFORSEO_WRITE,
            TokenAbilities::ADMIN,
        ]);

        $consumerId = (int) $request->user()->getKey();

        try {
            /** @var Account $account */
            $account = Account::query()
                ->where('consumer_id', $consumerId)
                ->findOrFail($request->integer('account_id'));
        } catch (ModelNotFoundException) {
            return $this->notFound('account_not_found', 'Account niet gevonden voor deze Consumer.');
        }

        $provider = $request->string('provider')->toString();

        $data = [
            'provider' => $provider,
            'status' => 'active',
            'administratie_id' => $request->input('administratie_id'),
            'metadata' => null,
        ];

        if ($provider === Provider::Snelstart->value) {
            $data['client_key'] = $request->input('credentials.client_key');
            $data['subscription_key'] = $request->input('credentials.subscription_key');
            $data['subscription_id'] = $request->input('credentials.subscription_id');
        } elseif ($provider === Provider::DataForSeo->value) {
            $data['access_token'] = $request->input('credentials.access_token');
        }

        try {
            $connection = $account->connections()->create($data);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'error' => 'connection_exists',
                'message' => 'Een actieve Connection bestaat al voor dit Account.',
            ], Response::HTTP_CONFLICT);
        }

        return (new ConnectionResource($connection))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, string $connection): JsonResponse|ConnectionResource
    {
        $this->guardAbility($request, [
            TokenAbilities::SNELSTART_READ,
            TokenAbilities::SNELSTART_WRITE,
            TokenAbilities::DATAFORSEO_READ,
            TokenAbilities::DATAFORSEO_WRITE,
            TokenAbilities::EXACT_READ,
            TokenAbilities::EXACT_WRITE,
            TokenAbilities::INTEGRATIONS_MANAGE,
            TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            TokenAbilities::ADMIN,
        ]);

        $model = $this->findOwnedConnection($request, $connection);

        if ($model === null) {
            return $this->notFound('connection_not_found', 'Connection niet gevonden.');
        }

        return new ConnectionResource($model);
    }

    public function destroy(Request $request, string $connection): JsonResponse|HttpResponse
    {
        $this->guardAbility($request, [
            TokenAbilities::INTEGRATIONS_MANAGE,
            TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            TokenAbilities::SNELSTART_WRITE,
            TokenAbilities::DATAFORSEO_WRITE,
            TokenAbilities::EXACT_WRITE,
            TokenAbilities::ADMIN,
        ]);

        $model = $this->findOwnedConnection($request, $connection);

        if ($model === null || $model->revoked_at !== null) {
            return $this->notFound('connection_not_found', 'Connection niet gevonden.');
        }

        $this->revokeConnection->handle($model);

        return response()->noContent();
    }

    private function findOwnedConnection(Request $request, string $connectionId): ?Connection
    {
        $consumerId = (int) $request->user()->getKey();

        $query = Connection::query()
            ->whereHas('account', fn ($q) => $q->where('consumer_id', $consumerId));

        if (ctype_digit($connectionId)) {
            return $query->find((int) $connectionId);
        }

        return $query->where('public_id', $connectionId)->first();
    }

    private function notFound(string $error, string $message): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'message' => $message,
        ], Response::HTTP_NOT_FOUND);
    }
}
