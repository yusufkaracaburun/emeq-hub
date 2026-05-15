<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

/*
 * Plan 09-10: UserResource form. Twee velden:
 *  - email (unique, ignoreRecord op edit)
 *  - password (alleen verplicht op create; bij edit alleen rehashen als ingevuld)
 *
 * Password-veld gebruikt dehydrateStateUsing(Hash::make) + dehydrated(filled)
 * zodat bij edit de bestaande hash bewaard blijft als veld leeg is.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(User::class, 'email', ignoreRecord: true),

                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn ($state) => Hash::make((string) $state))
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create'),
            ]);
    }
}
