<?php

declare(strict_types=1);

namespace App\Filament\Books\Support;

use App\Books\Models\Bill;
use App\Books\Models\BankAccount;
use App\Books\Models\Invoice;
use App\Books\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/*
 * Gedeelde doc-driven betaal-acties voor de Invoice- en Bill-tabel: registreren
 * (genereert de bank-boeking via PaymentService) en de laatste betaling
 * terugdraaien. Werkt op zowel Invoice als Bill via de HasPayments-API.
 */
class PaymentActions
{
    public static function register(): Action
    {
        return Action::make('betaling')
            ->label('Betaling')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Invoice|Bill $record): bool => $record->isPosted() && ! $record->isPaid())
            ->schema([
                Select::make('bank_account_id')
                    ->label('Bankrekening')
                    ->options(self::bankOptions())
                    ->default(self::defaultBankAccountId())
                    ->required(),

                TextInput::make('amount')
                    ->label('Bedrag')
                    ->numeric()
                    ->prefix('€')
                    ->step('0.01')
                    ->minValue(0.01)
                    ->default(fn (Invoice|Bill $record): string => number_format($record->amountDue() / 100, 2, '.', ''))
                    ->required(),

                DatePicker::make('date')
                    ->label('Datum')
                    ->default(now())
                    ->required(),
            ])
            ->action(function (array $data, Invoice|Bill $record): void {
                app(PaymentService::class)->register(
                    $record,
                    (int) $data['bank_account_id'],
                    (int) round(((float) $data['amount']) * 100),
                    $data['date'],
                );

                Notification::make()->title('Betaling geregistreerd')->success()->send();
            });
    }

    public static function undo(): Action
    {
        return Action::make('betaling_terugdraaien')
            ->label('Betaling terugdraaien')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Invoice|Bill $record): bool => $record->amountPaid() > 0)
            ->requiresConfirmation()
            ->modalDescription('Verwijdert de laatste betaling én de bijbehorende bank-boeking.')
            ->action(function (Invoice|Bill $record): void {
                $last = $record->payments()->latest('id')->first();

                if ($last === null) {
                    return;
                }

                app(PaymentService::class)->remove($last);

                Notification::make()->title('Laatste betaling teruggedraaid')->success()->send();
            });
    }

    /**
     * @return array<int, string>
     */
    private static function bankOptions(): array
    {
        return BankAccount::query()
            ->with('account')
            ->get()
            ->mapWithKeys(fn (BankAccount $bank): array => [
                $bank->id => $bank->account
                    ? "{$bank->account->code} — {$bank->account->name}"
                    : "Bankrekening #{$bank->id}",
            ])
            ->all();
    }

    private static function defaultBankAccountId(): ?int
    {
        return BankAccount::query()->value('id');
    }
}
