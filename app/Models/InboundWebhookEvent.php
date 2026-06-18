<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InboundWebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inbound partner→Hub webhook-event-audit (provider-agnostisch). Eigen concern,
 * los van `PassThroughCall` (= Consumer→Hub→Partner pass-through + accounting).
 *
 * **Metadata-only — géén payload/headers** (AVG). Genoeg getypte velden voor
 * incident-triage (provider/topic/action/outcome/status/fanout), niet de inhoud.
 * Geschreven via `App\Webhooks\InboundWebhookRecorder` (één write-path).
 */
#[Fillable([
    'provider',
    'event_id',
    'topic',
    'action',
    'connection_id',
    'account_id',
    'consumer_id',
    'status',
    'outcome',
    'fanout_status',
    'request_fingerprint',
    'received_at',
])]
class InboundWebhookEvent extends Model
{
    /** @use HasFactory<InboundWebhookEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Consumer::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
