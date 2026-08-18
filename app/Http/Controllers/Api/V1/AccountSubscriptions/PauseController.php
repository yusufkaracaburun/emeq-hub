<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\AccountSubscriptions;

use App\Billing\Account\AccountSubscriptionManager;
use App\Billing\Account\Exceptions\InvalidStateTransitionException;
use App\Http\Controllers\Api\V1\AccountSubscriptions\Concerns\HandlesAccountSubscriptionRequests;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccountSubscriptionResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Account Subscriptions', description: 'Multi-tenant subscription-state per Account+Connection (use-case B — Accounts factureren hun eigen eindgebruikers via Connect).', weight: 70)]
class PauseController extends Controller
{
    use HandlesAccountSubscriptionRequests;

    public function __construct(
        private readonly AccountSubscriptionManager $manager,
    ) {}

    public function __invoke(Request $request, int $id): JsonResponse|AccountSubscriptionResource
    {
        $sub = $this->findOwnedSubscription($request, $id);

        if ($sub === null) {
            return $this->notFound('account_subscription_not_found', 'Subscription niet gevonden.');
        }

        $reason = (string) $request->input('reason', 'manual_pause');

        try {
            $this->manager->pause($sub, $reason);
        } catch (InvalidStateTransitionException $e) {
            return $this->stateConflict($e);
        }

        $this->auditCall(
            $request,
            Response::HTTP_OK,
            '/v1/account-subscriptions/{id}/pause',
            $sub->account_id,
            $sub->connection_id,
        );

        return new AccountSubscriptionResource($sub->fresh());
    }
}
