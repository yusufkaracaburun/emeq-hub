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

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->request_id ??= Context::get('request_id');
        });
    }

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
