<?php

namespace App\Http\Controllers\Api\V1;

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
    public function store(StoreConnectionRequest $request): JsonResponse|ConnectionResource
    {
        $this->guardAbility($request, [
            TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            TokenAbilities::SNELSTART_WRITE,
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

        try {
            $connection = $account->connections()->create([
                'provider' => $request->string('provider')->toString(),
                'status' => 'active',
                'client_key' => $request->input('credentials.client_key'),
                'subscription_key' => $request->input('credentials.subscription_key'),
                'subscription_id' => $request->input('credentials.subscription_id'),
                'metadata' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'error' => 'connection_exists',
                'message' => 'Een actieve Snelstart-Connection bestaat al voor dit Account.',
            ], Response::HTTP_CONFLICT);
        }

        return (new ConnectionResource($connection))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, int $connection): JsonResponse|ConnectionResource
    {
        $this->guardAbility($request, [
            TokenAbilities::SNELSTART_READ,
            TokenAbilities::SNELSTART_WRITE,
            TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            TokenAbilities::ADMIN,
        ]);

        $model = $this->findOwnedConnection($request, $connection);

        if ($model === null) {
            return $this->notFound('connection_not_found', 'Connection niet gevonden.');
        }

        return new ConnectionResource($model);
    }

    public function destroy(Request $request, int $connection): JsonResponse|HttpResponse
    {
        $this->guardAbility($request, [
            TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            TokenAbilities::SNELSTART_WRITE,
            TokenAbilities::ADMIN,
        ]);

        $model = $this->findOwnedConnection($request, $connection);

        if ($model === null || $model->revoked_at !== null) {
            return $this->notFound('connection_not_found', 'Connection niet gevonden.');
        }

        $model->update(['revoked_at' => now()]);

        return response()->noContent();
    }

    private function findOwnedConnection(Request $request, int $connectionId): ?Connection
    {
        $consumerId = (int) $request->user()->getKey();

        return Connection::query()
            ->whereHas('account', fn ($q) => $q->where('consumer_id', $consumerId))
            ->find($connectionId);
    }

    private function notFound(string $error, string $message): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'message' => $message,
        ], Response::HTTP_NOT_FOUND);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function guardAbility(Request $request, array $allowed): void
    {
        $token = $request->user()?->currentAccessToken();
        $has = $token && collect($allowed)->contains(fn (string $ability) => $token->can($ability));

        abort_unless($has, Response::HTTP_FORBIDDEN, 'insufficient_ability');
    }
}
