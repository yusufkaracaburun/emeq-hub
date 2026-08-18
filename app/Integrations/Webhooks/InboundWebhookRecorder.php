<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Models\Connection;
use App\Models\InboundWebhookEvent;
use Illuminate\Http\Request;

final class InboundWebhookRecorder
{
    public const OUTCOME_PROCESSED = 'processed';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_UNKNOWN_TENANT = 'unknown_tenant';

    public const OUTCOME_MALFORMED = 'malformed';

    public const OUTCOME_INVALID_SIGNATURE = 'invalid_signature';

    public const OUTCOME_MISCONFIGURED = 'misconfigured';

    public const FANOUT_DISPATCHED = 'dispatched';

    public const FANOUT_SKIPPED = 'skipped_no_callback';

    public const FANOUT_NOT_APPLICABLE = 'not_applicable';

    public function record(
        string $provider,
        Request $request,
        int $status,
        string $outcome,
        ?string $eventId = null,
        ?string $topic = null,
        ?string $action = null,
        ?Connection $connection = null,
        ?string $fanoutStatus = null,
    ): InboundWebhookEvent {
        $account = $connection?->account;

        return InboundWebhookEvent::create([
            'provider' => $provider,
            'event_id' => $outcome === self::OUTCOME_DUPLICATE ? null : $eventId,
            'topic' => $topic,
            'action' => $action,
            'connection_id' => $connection?->getKey(),
            'account_id' => $account?->getKey(),
            'consumer_id' => $account?->consumer_id,
            'status' => $status,
            'outcome' => $outcome,
            'fanout_status' => $fanoutStatus,
            'request_fingerprint' => $this->fingerprint($request->getContent()),
            'received_at' => now(),
        ]);
    }

    public function isDuplicate(string $provider, string $eventId): bool
    {
        return InboundWebhookEvent::query()
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->exists();
    }

    private function fingerprint(string $rawBody): ?string
    {
        return $rawBody === '' ? null : mb_substr(hash('sha256', $rawBody), 0, 12);
    }
}
