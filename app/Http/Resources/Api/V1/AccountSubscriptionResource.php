<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AccountSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccountSubscription */
class AccountSubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'mollie_customer_id' => $this->mollie_customer_id,
            'mollie_subscription_id' => $this->mollie_subscription_id,
            'mollie_mandate_id' => $this->mollie_mandate_id,
            'amount' => [
                'currency' => $this->amount_currency,
                'value' => $this->amount_value,
            ],
            'interval' => $this->interval,
            'description' => $this->description,
            'times' => $this->times,
            'start_date' => $this->start_date?->toDateString(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'last_payment_status' => $this->last_payment_status,
            'last_webhook_event_at' => $this->last_webhook_event_at?->toIso8601String(),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
