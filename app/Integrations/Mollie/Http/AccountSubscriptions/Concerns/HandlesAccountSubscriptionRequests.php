<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\AccountSubscriptions\Concerns;

use App\Billing\Account\Exceptions\InvalidStateTransitionException;
use App\Enums\Provider;
use App\Integrations\Mollie\Errors\UpstreamErrorMapper;
use App\Integrations\PassThrough\PassThroughRecorder;
use App\Models\AccountSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

trait HandlesAccountSubscriptionRequests
{
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

    protected function mollieError(Throwable $e): JsonResponse
    {
        $mapped = UpstreamErrorMapper::mapException($e);

        return response()->json($mapped['body'], $mapped['status'], $mapped['headers']);
    }

    protected function auditCall(
        Request $request,
        int $status,
        string $path,
        ?int $accountId = null,
        ?int $connectionId = null,
        ?string $responseBody = null,
    ): void {
        if ($accountId === null) {
            return;
        }

        app(PassThroughRecorder::class)->record(
            provider: Provider::Mollie,
            consumerId: (int) $request->user()?->getKey(),
            accountId: $accountId,
            connectionId: $connectionId,
            method: $request->method(),
            path: $path,
            status: $status,
            responseBody: $responseBody,
        );
    }
}
