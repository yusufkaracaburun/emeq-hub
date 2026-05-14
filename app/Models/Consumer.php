<?php

namespace App\Models;

use Database\Factories\ConsumerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'slug'])]
class Consumer extends Authenticatable
{
    /** @use HasFactory<ConsumerFactory> */
    use HasApiTokens, HasFactory;

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
