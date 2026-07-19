<?php

declare(strict_types=1);

namespace App\Filament\Books\Support;

use App\Books\Enums\TransactionType;
use App\Books\Models\BankAccount;
use App\Books\Models\Bill;
use App\Books\Models\Invoice;
use App\Books\Models\Transaction;
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
     * Bank-driven: letter een bestaande bank-Transaction (geboekt op 1300/1600,
     * nog niet volledig toegewezen) af tegen een open post. Hoort op de
     * Transactions-tabel.
     */
    public static function reconcile(): Action
    {
        return Action::make('afletteren')
            ->label('Afletteren')
            ->icon(Heroicon::OutlinedLink)
            ->color('success')
            ->visible(fn (Transaction $record): bool => self::isReconcilable($record))
            ->schema([
                Select::make('document_id')
                    ->label('Open post')
                    ->options(fn (Transaction $record): array => self::openDocumentOptions($record))
                    ->required()
                    ->native(false),

                TextInput::make('amount')
                    ->label('Bedrag')
                    ->numeric()
                    ->prefix('€')
                    ->step('0.01')
                    ->minValue(0.01)
                    ->default(fn (Transaction $record): string => number_format($record->unallocatedAmount() / 100, 2, '.', ''))
                    ->required(),
            ])
            ->action(function (array $data, Transaction $record): void {
                $document = self::resolveDocument($record, (int) $data['document_id']);

                if ($document === null) {
                    return;
                }

                app(PaymentService::class)->reconcile(
                    $record,
                    $document,
                    (int) round(((float) $data['amount']) * 100),
                );

                Notification::make()->title('Afgeletterd')->success()->send();
            });
    }

    private static function isReconcilable(Transaction $transaction): bool
    {
        return in_array($transaction->type, [TransactionType::Deposit, TransactionType::Withdrawal], true)
            && in_array($transaction->account?->code, ['1300', '1600'], true)
            && $transaction->unallocatedAmount() > 0;
    }

    /**
     * @return array<int, string>
     */
    private static function openDocumentOptions(Transaction $transaction): array
    {
        $documents = $transaction->type === TransactionType::Deposit
            ? Invoice::query()->whereNotNull('transaction_id')->get()
            : Bill::query()->whereNotNull('transaction_id')->get();

        return $documents
            ->filter(fn (Invoice|Bill $document): bool => $document->amountDue() > 0)
            ->mapWithKeys(fn (Invoice|Bill $document): array => [
                $document->id => self::documentLabel($document),
            ])
            ->all();
    }

    private static function resolveDocument(Transaction $transaction, int $id): Invoice|Bill|null
    {
        return $transaction->type === TransactionType::Deposit
            ? Invoice::find($id)
            : Bill::find($id);
    }

    private static function documentLabel(Invoice|Bill $document): string
    {
        $number = $document instanceof Invoice
            ? ($document->invoice_number ?? '#'.$document->id)
            : ($document->bill_number ?? '#'.$document->id);

        return $number.' — openstaand € '.number_format($document->amountDue() / 100, 2, ',', '.');
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
