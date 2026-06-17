<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hub-brede idempotentie-record per (Consumer, Idempotency-Key). Bewaart de eerste
 * succesvolle respons (raw body + status + content-type) zodat een retry met dezelfde
 * key wordt herhaald in plaats van opnieuw uitgevoerd. Beheerd door EnsureIdempotency.
 */
class IdempotencyKey extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'consumer_id',
        'key',
        'method',
        'path',
        'response_status',
        'content_type',
        'response_body',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Consumer::class);
    }
}
