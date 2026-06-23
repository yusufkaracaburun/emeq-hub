<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Vendors\Schemas;

use App\Rules\ValidVatNumber;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Naam')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('E-mail')
                    ->email()
                    ->maxLength(255),

                TextInput::make('phone')
                    ->label('Telefoon')
                    ->tel()
                    ->maxLength(50),

                TextInput::make('vat_number')
                    ->label('BTW-nummer')
                    ->rule(new ValidVatNumber)
                    ->maxLength(20),

                TextInput::make('coc_number')
                    ->label('KvK-nummer')
                    ->maxLength(20),

                TextInput::make('address_line_1')
                    ->label('Adres')
                    ->maxLength(255),

                TextInput::make('address_line_2')
                    ->label('Adres (regel 2)')
                    ->maxLength(255),

                TextInput::make('postal_code')
                    ->label('Postcode')
                    ->maxLength(10),

                TextInput::make('city')
                    ->label('Plaats')
                    ->maxLength(255),

                TextInput::make('country_code')
                    ->label('Land')
                    ->default('NL')
                    ->maxLength(2),

                TextInput::make('website')
                    ->label('Website')
                    ->url()
                    ->maxLength(255),

                Textarea::make('notes')
                    ->label('Notities')
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }
}
