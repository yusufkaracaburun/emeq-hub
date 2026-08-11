<?php

namespace App\Models;

use Database\Factories\PassThroughCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Context;

#[Fillable([
    'direction',
    'consumer_id',
    'account_id',
    'connection_id',
    'provider',
    'token_type',
    'method',
    'path',
    'query_keys',
    'status',
    'duration_ms',
    'request_fingerprint',
    'partner_token_fingerprint',
    'event_id',
    'request_id',
    'response_size_bytes',
    'upstream_error',
    'response_body',
    'created_at',
])]
class PassThroughCall extends Model
{
    /** @use HasFactory<PassThroughCallFactory> */
    use HasFactory;

    use MassPrunable;

    public $timestamps = false;

    /**
     * Er zijn zeven plekken die een audit-rij schrijven. Het correlatie-id hier
     * ophalen in plaats van bij elke writer voorkomt dat de achtste hem vergeet.
     */
    protected static function booted(): void
    {
        static::creating(function (self $call): void {
            $call->request_id ??= Context::get('request_id');
        });
    }

    /**
     * Rijen ouder dan het retentie-venster (config `hub.retention.pass_through_days`).
     * 0 = pruning uit → match niets.
     */
    public function prunable(): Builder
    {
        $days = (int) config('hub.retention.pass_through_days', 90);

        if ($days <= 0) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('created_at', '<=', now()->subDays($days));
    }

    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', 'inbound');
    }

    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', 'outbound');
    }

    /**
     * Response-body voor de audit-detail: alleen bij fouten (status >= 400),
     * afgekapt op 8 KB. Rauwe tokens zitten in request-headers (niet de response);
     * de cap beperkt PII/grootte at rest. Eén bron voor alle pass-through-writers.
     */
    public static function errorBody(int $status, ?string $body): ?string
    {
        if ($status < 400 || $body === null || $body === '') {
            return null;
        }

        return mb_strlen($body) > 8000
            ? mb_substr($body, 0, 8000)."\n…[afgekapt]"
            : $body;
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duration_ms' => 'integer',
            'response_size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
