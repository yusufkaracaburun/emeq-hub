<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AccessRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessRequest extends Model
{
    /** @use HasFactory<AccessRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'company',
        'contact_name',
        'email',
        'app_url',
        'providers',
        'message',
        'privacy_accepted_at',
        'status',
        'consumer_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'providers' => 'array',
            'privacy_accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Consumer, $this> */
    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Consumer::class);
    }
}
