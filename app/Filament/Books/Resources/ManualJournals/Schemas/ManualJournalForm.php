<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\ManualJournals\Schemas;

use App\Books\Enums\JournalEntryType;
use App\Books\Models\Account;
use App\Books\Services\ManualJournalPoster;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ManualJournalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DateTimePicker::make('posted_at')
                    ->label('Boekdatum')
                    ->default(now())
                    ->seconds(false)
                    ->required(),

                TextInput::make('description')
                    ->label('Omschrijving')
                    ->maxLength(255)
                    ->required(),

                TextInput::make('reference')
                    ->label('Referentie')
                    ->maxLength(255),

                Repeater::make('lines')
                    ->label('Regels')
                    ->schema([
                        Select::make('account_id')
                            ->label('Grootboekrekening')
                            ->options(fn (): array => Account::query()
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (Account $account): array => [
                                    $account->getKey() => "{$account->code} — {$account->name}",
                                ])
                                ->all())
                            ->searchable()
                            ->required()
                            ->columnSpan(2),

                        Select::make('type')
                            ->label('Debet/Credit')
                            ->options([
                                JournalEntryType::Debit->value => 'Debet',
                                JournalEntryType::Credit->value => 'Credit',
                            ])
                            ->default(JournalEntryType::Debit->value)
                            ->required()
                            ->native(false),

                        TextInput::make('amount')
                            ->label('Bedrag')
                            ->numeric()
                            ->prefix('€')
                            ->minValue(0)
                            ->step('0.01')
                            ->required()
                            ->dehydrateStateUsing(fn (?string $state): int => (int) round(((float) $state) * 100)),

                        TextInput::make('description')
                            ->label('Toelichting')
                            ->maxLength(255)
                            ->columnSpan(2),
                    ])
                    ->columns(6)
                    ->defaultItems(2)
                    ->minItems(2)
                    ->addActionLabel('Regel toevoegen')
                    ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                        $lines = collect(is_array($value) ? $value : [])
                            ->map(static fn (array $line): array => [
                                'type' => JournalEntryType::tryFrom((string) ($line['type'] ?? '')),
                                'amount' => (int) round(((float) ($line['amount'] ?? 0)) * 100),
                            ])
                            ->all();

                        if ($error = ManualJournalPoster::balanceError($lines)) {
                            $fail($error);
                        }
                    })
                    ->columnSpanFull(),

                Textarea::make('notes')
                    ->label('Notities')
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }
}
