<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProviderEntityLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Welke canonieke entity hoort bij welke entity aan partnerzijde, per Connection.
 *
 * Provider-neutraal: `provider_entity_id` draagt een Exact-GUID, een Moneybird-id of
 * wat de volgende partner ook teruggeeft. Het canonieke domein blijft daar vrij van.
 *
 * Bewust géén `MassPrunable`: dit is geen audit-spoor maar de identiteitstabel die
 * dubbele boekingen tegenhoudt. Een rij prunen opent precies het gat dat de tabel
 * dicht. Rijen verdwijnen alleen met hun Connection (cascade).
 */
/**
 * Statische analyse leest `casts()` niet en valt terug op het kolomtype uit de
 * migratie. Zie ook {@see Connection}.
 *
 * @property Carbon|null $last_synced_at
 */
#[Fillable([
    'connection_id',
    'provider',
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

    /** De Hub schreef deze entity naar de partner. */
    public const ORIGIN_HUB = 'hub';

    /** De Hub trof deze entity aan de partnerzijde aan. */
    public const ORIGIN_PROVIDER = 'provider';

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
            'last_synced_at' => 'datetime',
        ];
    }
}
