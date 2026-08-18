<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\LedgerAccounts\Schemas;

use App\Books\Enums\AccountType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class LedgerAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Rekeningnummer')
                    ->required()
                    ->maxLength(20),

                TextInput::make('name')
                    ->label('Naam')
                    ->required()
                    ->maxLength(255),

                Select::make('type')
                    ->label('Type')
                    ->options(collect(AccountType::cases())
                        ->mapWithKeys(fn (AccountType $type): array => [$type->value => $type->getLabel()])
                        ->all())
                    ->required()
                    ->native(false),

                Select::make('subtype_id')
                    ->label('Subcategorie')
                    ->relationship('subtype', 'name')
                    ->searchable()
                    ->preload(),

                TextInput::make('currency_code')
                    ->label('Valuta')
                    ->default('EUR')
                    ->maxLength(3),

                Textarea::make('description')
                    ->label('Omschrijving')
                    ->maxLength(1000)
                    ->columnSpanFull(),

                Toggle::make('archived')
                    ->label('Gearchiveerd'),
            ]);
    }
}
