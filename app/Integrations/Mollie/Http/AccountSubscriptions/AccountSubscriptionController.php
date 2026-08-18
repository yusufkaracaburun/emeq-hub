<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\AccountSubscriptions;

use App\Integrations\Mollie\Billing\AccountSubscriptionManager;
use App\Integrations\Mollie\Billing\Dto\CreateAccountSubscriptionDto;
use App\Billing\Account\Exceptions\InvalidStateTransitionException;
use App\Enums\Provider;
use App\Integrations\Mollie\Http\AccountSubscriptions\Concerns\HandlesAccountSubscriptionRequests;
use App\Http\Controllers\Controller;
use App\Integrations\Mollie\Http\AccountSubscriptions\Requests\CreateAccountSubscriptionRequest;
use App\Http\Resources\Api\V1\AccountSubscriptionResource;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use DateTimeImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response as HttpResponse;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

#[Group(name: 'Account Subscriptions', description: 'Multi-tenant subscription-state per Account+Connection (use-case B — Accounts factureren hun eigen eindgebruikers via Connect).', weight: 70)]
class AccountSubscriptionController extends Controller
{
    use HandlesAccountSubscriptionRequests;

    public function __construct(
        private readonly AccountSubscriptionManager $manager,
    ) {}

    public function store(CreateAccountSubscriptionRequest $request): JsonResponse|AccountSubscriptionResource
    {
        /** @var Consumer $consumer */
        $consumer = $request->user();
        $validated = $request->validated();

        try {
            /** @var Account $account */
            $account = $consumer->accounts()
                ->where('external_id', $validated['account_external_id'])
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            return $this->notFound('account_not_found', 'Account niet gevonden voor deze Consumer.');
        }

        /** @var Connection|null $connection */
        $connection = $account->connections()
            ->where('provider', Provider::Mollie->value)
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null) {
            return response()->json([
                'error' => 'no_active_mollie_connection',
                'message' => 'Geen actieve Mollie-Connection gevonden voor dit Account.',
            ], Response::HTTP_CONFLICT);
        }

        $startDate = isset($validated['start_date']) && $validated['start_date'] !== null
            ? new DateTimeImmutable($validated['start_date'])
            : null;

        $dto = new CreateAccountSubscriptionDto(
            mollieCustomerId: $validated['mollie_customer_id'],
            mollieMandateId: $validated['mollie_mandate_id'] ?? null,
            amountCurrency: $validated['amount']['currency'],
            amountValue: $validated['amount']['value'],
            interval: $validated['interval'],
            description: $validated['description'],
            times: $validated['times'] ?? null,
            startDate: $startDate,
            metadata: $validated['metadata'] ?? null,
        );

        $idempotencyKey = $request->header('Idempotency-Key');

        try {
            $sub = $this->manager->create(
                $account,
                $connection,
                $dto,
                is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : null,
            );
        } catch (MollieApiException $e) {
            $this->auditCall($request, Response::HTTP_BAD_GATEWAY, '/v1/account-subscriptions', $account->id, $connection->id, $e->getMessage());

            return $this->mollieError($e);
        } catch (Throwable $e) {
            $this->auditCall($request, Response::HTTP_BAD_GATEWAY, '/v1/account-subscriptions', $account->id, $connection->id, $e->getMessage());

            return $this->mollieError($e);
        }

        $this->auditCall($request, Response::HTTP_CREATED, '/v1/account-subscriptions', $account->id, $connection->id);

        return (new AccountSubscriptionResource($sub))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function index(Request $request): JsonResponse|JsonResource
    {
        /** @var Consumer $consumer */
        $consumer = $request->user();

        $externalId = (string) $request->query('account_external_id', '');
        if ($externalId === '') {
            return response()->json([
                'error' => 'account_external_id_required',
                'message' => 'Query-parameter account_external_id is verplicht.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var Account|null $account */
        $account = $consumer->accounts()
            ->where('external_id', $externalId)
            ->first();

        if ($account === null) {
            return AccountSubscriptionResource::collection(collect());
        }

        $subs = $account->accountSubscriptions()->latest()->paginate(25);

        $this->auditCall($request, Response::HTTP_OK, '/v1/account-subscriptions', $account->id);

        return AccountSubscriptionResource::collection($subs);
    }

    public function show(Request $request, int $id): JsonResponse|AccountSubscriptionResource
    {
        $sub = $this->findOwnedSubscription($request, $id);

        if ($sub === null) {
            return $this->notFound('account_subscription_not_found', 'Subscription niet gevonden.');
        }

        if ($request->query('resync') === '1') {
            try {
                $this->manager->syncFromMollie($sub);
                $sub->refresh();
            } catch (MollieApiException $e) {
                return $this->mollieError($e);
            }
        }

        $this->auditCall($request, Response::HTTP_OK, '/v1/account-subscriptions/{id}', $sub->account_id, $sub->connection_id);

        return new AccountSubscriptionResource($sub);
    }

    public function destroy(Request $request, int $id): JsonResponse|HttpResponse
    {
        $sub = $this->findOwnedSubscription($request, $id);

        if ($sub === null) {
            return $this->notFound('account_subscription_not_found', 'Subscription niet gevonden.');
        }

        try {
            $this->manager->cancel($sub);
        } catch (InvalidStateTransitionException $e) {
            return $this->stateConflict($e);
        } catch (MollieApiException $e) {
            return $this->mollieError($e);
        }

        $this->auditCall($request, Response::HTTP_NO_CONTENT, '/v1/account-subscriptions/{id}', $sub->account_id, $sub->connection_id);

        return response()->noContent();
    }
}
