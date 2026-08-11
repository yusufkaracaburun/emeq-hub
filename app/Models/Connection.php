<?php

namespace App\Models;

use App\Enums\Provider;
use App\Support\ProviderCredentialDescriptor;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Statische analyse leest de casts uit {@see Connection::casts()} niet en valt terug
 * op het kolomtype uit de migratie — daardoor las `provider` als string en `metadata`
 * als string|null. Deze declaraties zetten dat recht.
 *
 * @property Provider $provider
 * @property array<string, mixed>|null $metadata
 * @property array<int, string>|null $scopes
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $oauth_state_expires_at
 */
#[Fillable([
    'account_id',
    'provider',
    'status',
    'access_token',
    'refresh_token',
    'expires_at',
    'scopes',
    'client_key',
    'subscription_key',
    'subscription_id',
    'administratie_id',
    'metadata',
    'revoked_at',
    'oauth_state',
    'oauth_state_expires_at',
    'oauth_return_url',
])]
#[Hidden(['access_token', 'refresh_token', 'client_key', 'subscription_key'])]
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use HasFactory;

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function accountSubscriptions(): HasMany
    {
        return $this->hasMany(AccountSubscription::class);
    }

    public function passThroughCalls(): HasMany
    {
        return $this->hasMany(PassThroughCall::class);
    }

    public function inboundWebhookEvents(): HasMany
    {
        return $this->hasMany(InboundWebhookEvent::class);
    }

    public function accountingRefs(): HasMany
    {
        return $this->hasMany(ConnectionAccountingRef::class);
    }

    /**
     * Reuse-or-create de (account, provider)-connection voor een OAuth-init.
     * Eén rij per (account, provider): voorkomt gestapelde pending-rijen bij
     * herhaalde connect-pogingen. Een al-active connection wordt op dezelfde
     * rij her-gekoppeld (status terug naar 'pending'); de bestaande tokens
     * blijven staan tot de callback nieuwe levert, zodat een afgebroken
     * re-link een werkende connectie niet weggooit (Prune skipt rijen mét
     * access_token).
     */
    public static function startOAuthFlow(
        Account $account,
        Provider $provider,
        string $state,
        ?string $returnUrl,
    ): self {
        $connection = static::firstOrNew([
            'account_id' => $account->id,
            'provider' => $provider->value,
        ]);

        $connection->fill([
            'status' => 'pending',
            'oauth_state' => $state,
            'oauth_state_expires_at' => now()->addMinutes(30),
            'oauth_return_url' => $returnUrl,
            // Hergebruik van een eerder losgekoppelde row: de revoke-markering
            // wissen, anders blijft de connection na reconnect 'active' mét
            // revoked_at en faalt een volgende DELETE op de revoked-guard (404).
            'revoked_at' => null,
        ])->save();

        return $connection;
    }

    public function fingerprint(): ?string
    {
        $descriptor = ProviderCredentialDescriptor::tryFor($this->provider->value);

        if ($descriptor === null) {
            return null;
        }

        $primaryField = $descriptor->encryptedFields[0] ?? null;

        if (! $primaryField) {
            return null;
        }

        $secret = $this->{$primaryField};

        return $secret ? substr(hash('sha256', (string) $secret), 0, 12) : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'client_key' => 'encrypted',
            'subscription_key' => 'encrypted',
            'scopes' => 'array',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'oauth_state_expires_at' => 'datetime',
        ];
    }
}
