<?php

namespace Database\Seeders;

use App\Books\Models\BankAccount;
use App\Books\Models\Bill;
use App\Books\Models\Invoice;
use App\Books\Models\Payment;
use App\Books\Services\PaymentService;
use Illuminate\Database\Seeder;

class BooksPaymentSeeder extends Seeder
{
    public function run(): void
    {
        if (Payment::query()->exists()) {
            return;
        }

        $bankAccountId = BankAccount::query()->value('id');

        if ($bankAccountId === null) {
            return;
        }

        $payments = app(PaymentService::class);
        $date = now()->toDateString();

        $invoice = Invoice::query()->whereNotNull('transaction_id')->first();

        if ($invoice !== null) {
            $payments->register($invoice, (int) $bankAccountId, (int) round($invoice->amountDue() / 2), $date);
        }

        $bill = Bill::query()->whereNotNull('transaction_id')->first();

        if ($bill !== null) {
            $payments->register($bill, (int) $bankAccountId, $bill->amountDue(), $date);
        }
    }
}
