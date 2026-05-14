<?php

namespace App\Models;

use Database\Factories\PassThroughCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'consumer_id',
    'account_id',
    'connection_id',
    'provider',
    'method',
    'path',
    'query_keys',
    'status',
    'duration_ms',
    'request_fingerprint',
    'response_size_bytes',
    'upstream_error',
    'created_at',
])]
class PassThroughCall extends Model
{
    /** @use HasFactory<PassThroughCallFactory> */
    use HasFactory;

    public $timestamps = false;

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
