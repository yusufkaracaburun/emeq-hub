<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Billing;

use App\Billing\PlanResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Billing\CreateSubscriptionRequest;
use App\Models\Consumer;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Cashier\Subscription;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

#[Group(name: 'Admin — Billing', description: 'Emeq-staff endpoints om Consumer-subscriptions te beheren (`admin`-ability vereist).', weight: 90)]
final class SubscriptionController extends Controller
{
    public function __construct(private readonly PlanResolver $planResolver) {}

    public function store(CreateSubscriptionRequest $request): JsonResponse
    {
        /** @var array{consumer_id:int, plan_slug:string, subscription_name?:string|null} $validated */
        $validated = $request->validated();

        $consumer = Consumer::query()->findOrFail($validated['consumer_id']);
        $subscriptionName = $validated['subscription_name'] ?? (string) config('billing.default_subscription_name', 'main');

        $this->planResolver->find($validated['plan_slug']);

        try {
            $builder = $consumer->newSubscription($subscriptionName, $validated['plan_slug']);
            $result = $builder->create();
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'subscription_create_failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        if ($result instanceof Subscription) {
            return response()->json([
                'subscription' => [
                    'id' => $result->id,
                    'consumer_id' => $consumer->id,
                    'name' => $result->name,
                    'plan' => $result->plan,
                ],
            ], Response::HTTP_CREATED);
        }

        if (is_object($result) && method_exists($result, 'getTargetUrl')) {
            return response()->json([
                'first_payment_required' => true,
                'mandate_redirect_url' => $result->getTargetUrl(),
            ], Response::HTTP_ACCEPTED);
        }

        return response()->json([
            'error' => 'unexpected_subscription_result',
            'message' => 'Cashier returned an unhandled result type.',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $subscription = Subscription::query()
            ->whereMorphedTo('owner', Consumer::class)
            ->findOrFail($id);

        try {
            $subscription->cancel();
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'subscription_cancel_failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
