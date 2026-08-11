<?php

declare(strict_types=1);

namespace App\Billing\Account;

use App\Billing\Account\Dto\CreateAccountSubscriptionDto;
use App\Integrations\Mollie\MollieConnectionContext;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use Emeq\MollieApi\Facades\Mollie;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Mollie\Api\Exceptions\NotFoundException as MollieNotFoundException;
use Mollie\Api\Resources\Subscription as MollieSubscription;

/**
 * Single-entry-point voor alle Mollie Subscription-flows + Hub-state-machine
 * transitions (07-CONTEXT.md D-13).
 *
 * Invariants:
 *  - Vóór elke Mollie-call: $this->context->set($connection) (D-13).
 *  - Elke state-flip via $this->transitionTo() (D-04 + D-22 logging).
 *  - State-machine bypass (direct $sub->status = '...') is verboden — manager
 *    is single-entry-point (T-07-03-03).
 *  - syncFromMollie() vangt NotFoundException → state Unknown (D-17).
 *  - recordPaymentEvent() met mandate_invalid → Active → Paused (D-16, SC-2).
 */
class AccountSubscriptionManager
{
    public function __construct(
        private readonly MollieConnectionContext $context,
    ) {}

    /**
     * Persist een pending Hub-row → roep Mollie aan → transitioneer naar
     * Active + vul mollie_subscription_id.
     *
     * @param  string|null  $idempotencyKey  Forward't via MollieApiClient::setIdempotencyKey()
     *                                       (D-14, T-07-03-01).
     *
     * @throws MollieApiException Plan 07-04 controllers mappen via
     *                            UpstreamErrorMapper; Hub-row blijft in
     *                            pending als evidence.
     */
    public function create(
        Account $account,
        Connection $connection,
        CreateAccountSubscriptionDto $dto,
        ?string $idempotencyKey = null,
    ): AccountSubscription {
        /** @var AccountSubscription $sub */
        $sub = AccountSubscription::query()->create([
            'account_id' => $account->getKey(),
            'connection_id' => $connection->getKey(),
            'mollie_customer_id' => $dto->mollieCustomerId,
            'mollie_mandate_id' => $dto->mollieMandateId,
            'status' => SubscriptionStatus::Pending->value,
            'amount_currency' => $dto->amountCurrency,
            'amount_value' => $dto->amountValue,
            'interval' => $dto->interval,
            'description' => $dto->description,
            'times' => $dto->times,
            'start_date' => $dto->startDate,
            'metadata' => $dto->metadata,
        ]);

        $this->context->set($connection);
        $client = Mollie::client();

        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $client->setIdempotencyKey($idempotencyKey);
        }

        $body = $this->buildMollieCreateBody($dto);

        $remote = $client->subscriptions->createForId($dto->mollieCustomerId, $body);

        $sub->mollie_subscription_id = $this->extractRemoteId($remote);
        $sub->starts_at = now();

        $this->transitionTo(
            $sub,
            SubscriptionStatus::Active,
            ['reason' => 'mollie_create_succeeded'],
        );

        return $sub->fresh();
    }

    /**
     * Cancel Mollie-side (alleen als mollie_subscription_id niet null) +
     * transitioneer naar Canceled + zet canceled_at.
     */
    public function cancel(AccountSubscription $sub): void
    {
        if ($sub->mollie_subscription_id !== null) {
            $this->context->set($sub->connection);
            Mollie::client()->subscriptions->cancelForId(
                $sub->mollie_customer_id,
                $sub->mollie_subscription_id,
            );
        }

        $sub->canceled_at = now();
        $this->transitionTo($sub, SubscriptionStatus::Canceled, ['reason' => 'manual_cancel']);
    }

    /**
     * Hub-only: Active → Paused (geen Mollie-call). Plan 07-CONTEXT.md D-04
     * + D-16 — gebruikt wanneer Consumer weet dat het mandaat ongeldig is.
     */
    public function pause(AccountSubscription $sub, string $reason): void
    {
        $sub->paused_at = now();
        $this->transitionTo($sub, SubscriptionStatus::Paused, ['reason' => $reason]);
    }

    /**
     * Hub-only: Paused → Active (geen Mollie-call). Reset paused_at.
     */
    public function resume(AccountSubscription $sub): void
    {
        $sub->paused_at = null;
        $this->transitionTo($sub, SubscriptionStatus::Active, ['reason' => 'manual_resume']);
    }

    /**
     * GET subscription via Mollie SDK; map remote status naar Hub-state. Bij
     * NotFoundException → Unknown (D-17). Onbekende remote-status logt en
     * behoudt huidige state.
     */
    public function syncFromMollie(AccountSubscription $sub): void
    {
        if ($sub->mollie_subscription_id === null) {
            return;
        }

        $this->context->set($sub->connection);

        try {
            $remote = Mollie::client()->subscriptions->getForId(
                $sub->mollie_customer_id,
                $sub->mollie_subscription_id,
            );
        } catch (MollieNotFoundException) {
            $this->transitionTo(
                $sub,
                SubscriptionStatus::Unknown,
                ['reason' => 'mollie_returned_404'],
            );

            return;
        }

        $remoteStatus = is_string($remote->status ?? null) ? $remote->status : null;
        $mapped = $this->mapMollieStatus($remoteStatus);

        if ($mapped === null) {
            Log::info('account_subscription.transition_skipped', [
                'subscription_id' => $sub->id,
                'reason' => 'unknown_mollie_status',
                'mollie_status' => $remoteStatus,
                'mollie_subscription_id' => $sub->mollie_subscription_id,
            ]);

            return;
        }

        if ($mapped === SubscriptionStatus::Canceled) {
            $sub->canceled_at = $sub->canceled_at ?? now();
        }
        if ($mapped === SubscriptionStatus::Completed) {
            $sub->completed_at = $sub->completed_at ?? now();
        }
        if ($mapped === SubscriptionStatus::Paused) {
            $sub->paused_at = $sub->paused_at ?? now();
        }

        $this->transitionTo($sub, $mapped, ['reason' => 'mollie_resync']);
    }

    /**
     * Webhook-handler-entry: inspecteer Mollie Payment-payload + flip state.
     *
     * - status='failed' + details.failureReason='mandate_invalid' → Active →
     *   Paused (D-16, SC-2). last_payment_status = 'failed_mandate_invalid'.
     * - status='failed' (andere reason) → bewaar reason in last_payment_status;
     *   geen state-flip.
     * - status='paid' → last_payment_status='paid' + last_webhook_event_at.
     *
     * @param  array<string, mixed>  $payment
     */
    public function recordPaymentEvent(AccountSubscription $sub, array $payment): void
    {
        $status = is_string($payment['status'] ?? null) ? $payment['status'] : null;
        $failureReason = null;
        if (isset($payment['details']) && is_array($payment['details'])) {
            $failureReason = is_string($payment['details']['failureReason'] ?? null)
                ? $payment['details']['failureReason']
                : null;
        }

        $sub->last_webhook_event_at = now();

        if ($status === 'paid') {
            $sub->last_payment_status = 'paid';
            $sub->save();

            return;
        }

        if ($status === 'failed') {
            $sub->last_payment_status = 'failed_'.($failureReason ?? 'unknown');

            if ($failureReason === 'mandate_invalid' && $sub->status === SubscriptionStatus::Active) {
                $sub->paused_at = now();
                $this->transitionTo($sub, SubscriptionStatus::Paused, [
                    'reason' => 'payment_failed_mandate_invalid',
                ]);

                return;
            }

            $sub->save();

            return;
        }

        $sub->save();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function transitionTo(
        AccountSubscription $sub,
        SubscriptionStatus $to,
        array $context = [],
    ): void {
        /** @var SubscriptionStatus $from */
        $from = $sub->status;

        StateTransitions::assertTransition($from, $to);

        $sub->status = $to;
        $sub->save();

        Log::info('account_subscription.transition', array_merge([
            'subscription_id' => $sub->id,
            'from' => $from->value,
            'to' => $to->value,
            'mollie_subscription_id' => $sub->mollie_subscription_id,
        ], $context));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMollieCreateBody(CreateAccountSubscriptionDto $dto): array
    {
        $body = [
            'amount' => [
                'currency' => $dto->amountCurrency,
                'value' => $dto->amountValue,
            ],
            'interval' => $dto->interval,
            'description' => $dto->description,
        ];

        if ($dto->mollieMandateId !== null) {
            $body['mandateId'] = $dto->mollieMandateId;
        }
        if ($dto->times !== null) {
            $body['times'] = $dto->times;
        }
        if ($dto->startDate !== null) {
            $body['startDate'] = $dto->startDate->format('Y-m-d');
        }
        if ($dto->metadata !== null) {
            $body['metadata'] = $dto->metadata;
        }

        return $body;
    }

    private function extractRemoteId(MollieSubscription $remote): ?string
    {
        $id = $remote->id ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function mapMollieStatus(?string $status): ?SubscriptionStatus
    {
        return match ($status) {
            'pending' => SubscriptionStatus::Pending,
            'active' => SubscriptionStatus::Active,
            'suspended' => SubscriptionStatus::Paused,
            'canceled', 'cancelled' => SubscriptionStatus::Canceled,
            'completed' => SubscriptionStatus::Completed,
            default => null,
        };
    }
}
