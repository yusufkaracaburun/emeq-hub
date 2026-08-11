<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InboundWebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Context;

/**
 * Inbound partner→Hub webhook-event-audit (provider-agnostisch). Eigen concern,
 * los van `PassThroughCall` (= Consumer→Hub→Partner pass-through + accounting).
 *
 * **Metadata-only — géén payload/headers** (AVG). Genoeg getypte velden voor
 * incident-triage (provider/topic/action/outcome/status/fanout), niet de inhoud.
 * Geschreven via `App\Integrations\Webhooks\InboundWebhookRecorder` (één write-path).
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
    'request_id',
    'received_at',
])]
class InboundWebhookEvent extends Model
{
    /** @use HasFactory<InboundWebhookEventFactory> */
    use HasFactory;

    use MassPrunable;

    public $timestamps = false;

    /**
     * Correlatie-id uit de request-context, zodat de recorder er niets van hoeft
     * te weten. Werkt ook binnen een queued job: het framework hydrateert
     * `Context` daar terug uit de job-payload.
     */
    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->request_id ??= Context::get('request_id');
        });
    }

    /**
     * Rijen ouder dan het retentie-venster (config `hub.retention.webhook_days`).
     * 0 = pruning uit → match niets.
     */
    public function prunable(): Builder
    {
        $days = (int) config('hub.retention.webhook_days', 90);

        if ($days <= 0) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('received_at', '<=', now()->subDays($days));
    }

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
