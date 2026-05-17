<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hub-eigen WebhookCall subclass van Spatie's webhook-client model.
 *
 * Plan 10-01 / D-2: Spatie's eigen model heeft géén consumer()-relatie.
 * Migration 2026_05_19_000001 voegt `consumer_id` toe als losse kolom; deze
 * subclass + `config/webhook-client.php` model-binding maakt eager-load
 * (`->with('consumer')`) mogelijk in Plan 10-04 (WebhookCallsTable / -Infolist).
 *
 * Bestaande Spatie-rijen met `consumer_id` NULL blijven valide — `consumer()`
 * is nullable.
 */
final class WebhookCall extends \Spatie\WebhookClient\Models\WebhookCall
{
    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Consumer::class);
    }
}
