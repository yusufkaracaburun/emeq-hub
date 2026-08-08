<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DemoRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Lead vanaf de publieke demo-aanvraag. Zusje van AccessRequest: die vraagt om
 * een koppeling, deze om een gesprek. Bewust een eigen tabel — de velden
 * verschillen (voorkeursmoment in plaats van providers) en de opvolging ook.
 */
class DemoRequest extends Model
{
    /** @use HasFactory<DemoRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'company',
        'contact_name',
        'email',
        'preferred_slot',
        'message',
        'privacy_accepted_at',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'privacy_accepted_at' => 'datetime',
        ];
    }
}
