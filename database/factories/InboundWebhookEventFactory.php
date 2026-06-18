<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InboundWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InboundWebhookEvent>
 */
class InboundWebhookEventFactory extends Factory
{
    protected $model = InboundWebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'exact',
            'event_id' => Str::random(32),
            'topic' => 'GeneralJournalEntries',
            'action' => 'Update',
            'status' => 200,
            'outcome' => 'processed',
            'fanout_status' => 'dispatched',
            'request_fingerprint' => mb_substr(hash('sha256', Str::random()), 0, 12),
            'received_at' => now(),
        ];
    }
}
