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
