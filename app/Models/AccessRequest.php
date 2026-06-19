<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AccessRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Onboarding-lead vanaf de publieke koppel-intake (formulier op de
 * partner-pagina's). Bewust losgekoppeld van
 * het Connection-model: dit is een aanvraag om gekoppeld te worden, geen
 * OAuth-koppeling.
 */
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
        'status',
        'consumer_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'providers' => 'array',
        ];
    }

    /**
     * De Consumer die uit deze aanvraag is ge-onboard (null = nog niet afgehandeld).
     *
     * @return BelongsTo<Consumer, $this>
     */
    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Consumer::class);
    }
}
