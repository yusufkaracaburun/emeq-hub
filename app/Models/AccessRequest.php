<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AccessRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Onboarding-lead vanaf de publieke /koppelen-intake. Bewust losgekoppeld van
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
}
