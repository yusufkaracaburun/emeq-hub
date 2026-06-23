<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\AccountSubscriptions\Concerns;

use App\Billing\Account\Exceptions\InvalidStateTransitionException;
use App\Enums\Provider;
use App\Models\AccountSubscription;
use App\Models\PassThroughCall;
use App\Support\Mollie\UpstreamErrorMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Gedeelde helpers voor de 3 AccountSubscriptions-controllers (resource,
 * Pause, Resume). Cross-Consumer-scope is per-Consumer (D-12 + 07-08 ADR):
 * pause/resume/destroy op een sub van een ander Account binnen DEZELFDE
 * Consumer is allowed; cross-Consumer = 404 (info-disclosure-protectie).
 */
trait HandlesAccountSubscriptionRequests
{
    /**
     * Cross-Consumer scope via Account → Consumer-FK. 404 op miss (not 403)
     * per T-07-04-01 + D-12.
     */
    protected function findOwnedSubscription(Request $request, int $id): ?AccountSubscription
    {
        $consumerId = (int) $request->user()?->getKey();

        return AccountSubscription::query()
            ->whereHas('account', fn ($q) => $q->where('consumer_id', $consumerId))
            ->find($id);
    }

    protected function notFound(string $error, string $message): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'message' => $message,
        ], Response::HTTP_NOT_FOUND);
    }

    protected function stateConflict(InvalidStateTransitionException $e): JsonResponse
    {
        return response()->json([
            'error' => 'invalid_state_transition',
            'message' => $e->getMessage(),
            'from' => $e->from->value,
            'to' => $e->to->value,
        ], Response::HTTP_CONFLICT);
    }

    /**
     * Mapt Mollie-upstream-exceptions via UpstreamErrorMapper (D-23,
     * 5a-pattern). Cloak't 401 → 502 zodat access-token-state niet lekt
     * (T-07-04-05).
     */
    protected function mollieError(Throwable $e): JsonResponse
    {
        $mapped = UpstreamErrorMapper::mapException($e);

        return response()->json($mapped['body'], $mapped['status'], $mapped['headers']);
    }

    /**
     * Schrijft een pass_through_calls-audit-rij (D-21). `provider='mollie'`
     * voor alle nieuwe /v1/account-subscriptions/* endpoints. Schema-kolommen
     * volgen 5a's create_pass_through_calls_table-migration (geen direction/
     * query_keys/event_id in v0.2; die zitten in Fillable voor toekomstige
     * schema-uitbreiding).
     */
    protected function auditCall(
        Request $request,
        int $status,
        string $path,
        ?int $accountId = null,
        ?int $connectionId = null,
        ?string $responseBody = null,
    ): void {
        // Account-id is FK NOT NULL in pass_through_calls; fallback op
        // request->user->id leeft hier niet (er is geen consumer-account-relatie
        // in dat schema). Skip audit als we niet eens een account_id konden
        // resolved'en — voorkomt FK-constraint-violation onder Postgres.
        if ($accountId === null) {
            return;
        }

        PassThroughCall::create([
            'consumer_id' => (int) $request->user()?->getKey(),
            'account_id' => $accountId,
            'connection_id' => $connectionId,
            'provider' => Provider::Mollie->value,
            'method' => $request->method(),
            'path' => $path,
            'status' => $status,
            'duration_ms' => 0,
            'response_body' => PassThroughCall::errorBody($status, $responseBody),
        ]);
    }
}
