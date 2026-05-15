<?php

namespace App\Models;

use Database\Factories\AccountSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'account_id',
    'connection_id',
    'mollie_customer_id',
    'mollie_subscription_id',
    'mollie_mandate_id',
    'status',
    'amount_currency',
    'amount_value',
    'interval',
    'description',
    'times',
    'start_date',
    'starts_at',
    'paused_at',
    'canceled_at',
    'completed_at',
    'metadata',
    'last_payment_status',
    'last_webhook_event_at',
])]
class AccountSubscription extends Model
{
    /** @use HasFactory<AccountSubscriptionFactory> */
    use HasFactory;

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
            'metadata' => 'array',
            'times' => 'integer',
            'start_date' => 'date',
            'starts_at' => 'datetime',
            'paused_at' => 'datetime',
            'canceled_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_webhook_event_at' => 'datetime',
        ];
    }
}
