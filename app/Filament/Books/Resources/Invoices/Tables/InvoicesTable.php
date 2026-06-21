<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Invoices\Tables;

use App\Books\Enums\InvoiceStatus;
use App\Books\Models\Invoice;
use App\Books\Services\InvoicePoster;
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

class InvoicesTable
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

                TextColumn::make('invoice_number')
                    ->label('Nr.')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('client.name')
                    ->label('Klant')
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
                    ->state(fn (Invoice $record): int => $record->amountDue())
                    ->badge()
                    ->color(fn (Invoice $record): string => match (true) {
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
                    ->options(collect(InvoiceStatus::cases())
                        ->mapWithKeys(fn (InvoiceStatus $status): array => [$status->value => $status->getLabel()])
                        ->all()),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('boeken')
                    ->label('Boeken')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Invoice $record): bool => ! $record->isPosted())
                    ->requiresConfirmation()
                    ->modalDescription('Boekt de verkoopboeking (debiteuren / omzet / BTW) naar het grootboek.')
                    ->action(function (Invoice $record): void {
                        app(InvoicePoster::class)->post($record);

                        Notification::make()->title('Factuur geboekt')->success()->send();
                    }),

                Action::make('ontboeken')
                    ->label('Ontboeken')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (Invoice $record): bool => $record->isPosted())
                    ->requiresConfirmation()
                    ->modalDescription('Verwijdert de grootboekboeking weer.')
                    ->action(function (Invoice $record): void {
                        app(InvoicePoster::class)->unpost($record);

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
