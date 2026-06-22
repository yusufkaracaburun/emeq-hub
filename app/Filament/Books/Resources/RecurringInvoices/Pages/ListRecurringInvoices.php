<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\RecurringInvoices\Pages;

use App\Books\Services\RecurringInvoiceGenerator;
use App\Filament\Books\Resources\RecurringInvoices\RecurringInvoiceResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListRecurringInvoices extends ListRecords
{
    protected static string $resource = RecurringInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateDue')
                ->label('Genereer nu')
                ->icon(Heroicon::OutlinedBolt)
                ->action(function (RecurringInvoiceGenerator $generator): void {
                    $count = $generator->generateDue();

                    Notification::make()
                        ->title("{$count} factuur(en) gegenereerd")
                        ->success()
                        ->send();
                }),

            CreateAction::make()->label('Nieuwe template'),
        ];
    }
}
