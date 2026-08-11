<?php

declare(strict_types=1);

namespace App\Models;

use App\Http\Middleware\EnsureIdempotency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Claim per (Consumer, Idempotency-Key). De unique index op die twee kolommen is de
 * mutex: {@see EnsureIdempotency} claimt de rij vóór de handler
 * draait en rondt hem daarna af met de respons.
 *
 * Twee staten. `in_flight` betekent dat er nú een request loopt; `completed` dat de
 * bewaarde respons herhaald mag worden. Een mislukte poging verwijdert de rij, zodat
 * hij opnieuw mag — dat was ook het gedrag vóór de claim-laag.
 *
 * @property Carbon|null $locked_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 */
class IdempotencyKey extends Model
{
    use MassPrunable;

    /** Er loopt een request voor deze key; `locked_at` draagt de lease. */
    public const STATE_IN_FLIGHT = 'in_flight';

    /** De respons is bewaard en wordt herhaald bij een retry. */
    public const STATE_COMPLETED = 'completed';

    public $timestamps = false;

    protected $fillable = [
        'consumer_id',
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

    /**
     * Het verval staat op de rij zelf, gezet bij het claimen. Geen `0 = uit`-ontsnapping
     * zoals bij de audit-tabellen: een key die eeuwig blijft staan is geen archief maar
     * een lek.
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }

    /**
     * Een claim waarvan de lease verlopen is, hoorde bij een request dat kennelijk
     * gestorven is. Zie de invariant bij `hub.idempotency.lease_seconds`.
     */
    public function leaseHasExpired(): bool
    {
        if ($this->state !== self::STATE_IN_FLIGHT || $this->locked_at === null) {
            return false;
        }

        return $this->locked_at->addSeconds(self::leaseSeconds())->isPast();
    }

    /**
     * Seconden tot de lease verloopt, minimaal 1 — bruikbaar als `Retry-After`.
     */
    public function secondsUntilLeaseExpires(): int
    {
        if ($this->locked_at === null) {
            return 1;
        }

        return max(1, (int) ceil(now()->diffInSeconds($this->locked_at->addSeconds(self::leaseSeconds()), false)));
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
