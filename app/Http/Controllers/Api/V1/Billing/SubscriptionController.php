<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Cashier\Subscription;

#[Group(name: 'Billing (Cashier)', description: 'Consumer-billing via Cashier-Mollie (use-case A — Emeq factureert Consumers via Emeq\'s eigen Mollie-account).', weight: 80)]
final class SubscriptionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $consumer = $request->user();
        $subscriptionName = (string) config('billing.default_subscription_name', 'main');

        /** @var Subscription|null $subscription */
        $subscription = $consumer->subscription($subscriptionName);

        if ($subscription === null) {
            return response()->json([
                'consumer_id' => $consumer->getKey(),
                'subscription_name' => $subscriptionName,
                'subscribed' => false,
            ]);
        }

        return response()->json([
            'consumer_id' => $consumer->getKey(),
            'subscription_name' => $subscriptionName,
            'subscribed' => true,
            'status' => $this->deriveStatus($subscription),
            'plan' => $subscription->plan,
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
        ]);
    }

    private function deriveStatus(Subscription $subscription): string
    {
        if ($subscription->onTrial()) {
            return 'trialing';
        }

        if ($subscription->ended()) {
            return 'ended';
        }

        if ($subscription->onGracePeriod()) {
            return 'grace';
        }

        if ($subscription->cancelled()) {
            return 'cancelled';
        }

        return $subscription->active() ? 'active' : 'inactive';
    }
}
