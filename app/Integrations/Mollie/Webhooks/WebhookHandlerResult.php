<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Webhooks;

/**
 * Value-object dat het uitkomst-contract tussen `WebhookPayloadRouter` /
 * Mollie-handlers en `MollieWebhookController` stolt (07-CONTEXT.md D-15/D-18).
 *
 * Drie statussen:
 *  - `ok`               — handler verwerkt, audit + fan-out vinden plaats
 *  - `skip`             — handler heeft niets te doen (bv. onbekende
 *                         `sub_*`-id of `mdt_*`-placeholder); audit-rij wel
 *                         schrijven voor diagnose; fan-out blijft staan
 *  - `anti_spoof_failed` — Mollie-resource-fetch faalde (404/auth-error);
 *                         audit-rij schrijven, géén fan-out (D-31 invariant
 *                         + bestaand Phase-5a-gedrag uit
 *                         `MollieWebhookAntiSpoofingTest`).
 */
final readonly class WebhookHandlerResult
{
    /**
     * Private-constructor-style: roep de static factories aan vanaf de
     * handlers in plaats van `new WebhookHandlerResult(...)`.
     */
    public function __construct(
        public string $status,
        public ?string $reason = null,
        public ?int $accountSubscriptionId = null,
        public bool $auditEnabled = true,
        public bool $fanOutEnabled = true,
    ) {}

    public static function ok(?int $accountSubscriptionId = null): self
    {
        return new self(
            status: 'ok',
            accountSubscriptionId: $accountSubscriptionId,
        );
    }

    public static function skip(string $reason): self
    {
        return new self(
            status: 'skip',
            reason: $reason,
        );
    }

    public static function antiSpoofFailed(string $message): self
    {
        return new self(
            status: 'anti_spoof_failed',
            reason: $message,
            fanOutEnabled: false,
        );
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    public function shouldAudit(): bool
    {
        return $this->auditEnabled;
    }

    public function shouldFanOut(): bool
    {
        return $this->fanOutEnabled && $this->status !== 'anti_spoof_failed';
    }
}
