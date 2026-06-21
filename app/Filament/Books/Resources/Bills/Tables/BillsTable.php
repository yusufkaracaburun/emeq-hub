<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Bills\Tables;

use App\Books\Enums\BillStatus;
use App\Books\Models\Bill;
use App\Books\Services\BillPoster;
use App\Filament\Books\Support\PaymentActions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BillsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')
                    ->label('Datum')
                    ->date('d-m-Y')
                    ->sortable(),

                TextColumn::make('bill_number')
                    ->label('Nr.')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('vendor.name')
                    ->label('Leverancier')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),

                IconColumn::make('transaction_id')
                    ->label('Geboekt')
                    ->boolean(),

                TextColumn::make('total')
                    ->label('Totaal')
                    ->formatStateUsing(fn (int $state): string => '€ '.number_format($state / 100, 2, ',', '.'))
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('amount_due')
                    ->label('Openstaand')
                    ->state(fn (Bill $record): int => $record->amountDue())
                    ->badge()
                    ->color(fn (Bill $record): string => match (true) {
                        $record->amountDue() === 0 && $record->amountPaid() > 0 => 'success',
                        $record->isPartiallyPaid() => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'Betaald' : '€ '.number_format($state / 100, 2, ',', '.'))
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(BillStatus::cases())
                        ->mapWithKeys(fn (BillStatus $status): array => [$status->value => $status->getLabel()])
                        ->all()),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('boeken')
                    ->label('Boeken')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Bill $record): bool => ! $record->isPosted())
                    ->requiresConfirmation()
                    ->modalDescription('Boekt de inkoopboeking (kosten / te vorderen BTW / crediteuren) naar het grootboek.')
                    ->action(function (Bill $record): void {
                        app(BillPoster::class)->post($record);

                        Notification::make()->title('Inkoopfactuur geboekt')->success()->send();
                    }),

                Action::make('ontboeken')
                    ->label('Ontboeken')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (Bill $record): bool => $record->isPosted())
                    ->requiresConfirmation()
                    ->modalDescription('Verwijdert de grootboekboeking weer.')
                    ->action(function (Bill $record): void {
                        app(BillPoster::class)->unpost($record);

                        Notification::make()->title('Boeking ongedaan gemaakt')->success()->send();
                    }),

                PaymentActions::register(),
                PaymentActions::undo(),

                EditAction::make(),
                DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
