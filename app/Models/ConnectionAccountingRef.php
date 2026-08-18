<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $attrs
 * @property Carbon|null $synced_at
 */
#[Fillable([
    'connection_id',
    'kind',
    'code',
    'native_id',
    'label',
    'attrs',
    'synced_at',
])]
class ConnectionAccountingRef extends Model
{
    public const KIND_GL = 'gl';

    public const KIND_VAT = 'vat';

    public const KIND_JOURNAL = 'journal';

    public const KIND_RELATION = 'relation';

    public const KIND_COST_CENTER = 'cost_center';

    public const KIND_COST_UNIT = 'cost_unit';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attrs' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
