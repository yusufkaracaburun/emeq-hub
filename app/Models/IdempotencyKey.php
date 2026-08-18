<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $locked_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 */
class IdempotencyKey extends Model
{
    use MassPrunable;

    public const STATE_IN_FLIGHT = 'in_flight';

    public const STATE_COMPLETED = 'completed';

    public $timestamps = false;

    protected $fillable = [
        'consumer_id',
        'account_id',
        'key',
        'method',
        'path',
        'state',
        'request_fingerprint',
        'response_status',
        'content_type',
        'response_body',
        'locked_at',
        'completed_at',
        'expires_at',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'locked_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }

    public function leaseHasExpired(): bool
    {
        if ($this->state !== self::STATE_IN_FLIGHT || $this->locked_at === null) {
            return false;
        }

        return $this->locked_at->addSeconds(self::leaseSeconds())->isPast();
    }

    private const RETRY_AFTER_CEILING_SECONDS = 10;

    public function secondsUntilLeaseExpires(): int
    {
        if ($this->locked_at === null) {
            return 1;
        }

        $remaining = (int) ceil(now()->diffInSeconds($this->locked_at->addSeconds(self::leaseSeconds()), false));

        return max(1, min($remaining, self::RETRY_AFTER_CEILING_SECONDS));
    }

    public static function retryAfterCeilingSeconds(): int
    {
        return self::RETRY_AFTER_CEILING_SECONDS;
    }

    public static function leaseSeconds(): int
    {
        return (int) config('hub.idempotency.lease_seconds', 900);
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Consumer::class);
    }
}
