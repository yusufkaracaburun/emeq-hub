<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProviderEntityLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon|null $last_synced_at */
#[Fillable([
    'connection_id',
    'consumer_id',
    'provider',
    'administratie_id',
    'entity_type',
    'entity_subtype',
    'external_id',
    'provider_entity_id',
    'provider_entity_number',
    'payload_fingerprint',
    'origin',
    'last_synced_at',
])]
class ProviderEntityLink extends Model
{
    /** @use HasFactory<ProviderEntityLinkFactory> */
    use HasFactory;

    public const ENTITY_FINANCIAL_DOCUMENT = 'financial_document';

    public const ENTITY_RELATION = 'relation';

    public const ENTITY_PURCHASE = 'purchase';

    public const ORIGIN_HUB = 'hub';

    public const ORIGIN_PROVIDER = 'provider';

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Consumer::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }
}
