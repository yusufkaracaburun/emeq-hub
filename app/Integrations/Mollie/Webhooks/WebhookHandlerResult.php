<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Webhooks;

final readonly class WebhookHandlerResult
{
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
