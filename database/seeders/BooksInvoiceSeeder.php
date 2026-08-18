<?php

namespace Database\Seeders;

use App\Books\Models\Account;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Books\Services\InvoicePoster;
use Illuminate\Database\Seeder;

class BooksInvoiceSeeder extends Seeder
{
    public function run(): void
    {
        if (Invoice::query()->exists()) {
            return;
        }

        $this->call(BooksRelationsSeeder::class);

        $client = Client::query()->firstOrFail();

        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => '2026-001',
            'status' => 'sent',
            'date' => now()->subDays(2),
            'due_date' => now()->addDays(12),
        ]);
        $invoice->lines()->create(['description' => 'Consultancy juni', 'quantity' => 8, 'unit_price' => 9500, 'tax_rate' => 21]);
        $invoice->lines()->create(['description' => 'Reiskosten', 'quantity' => 1, 'unit_price' => 4500, 'tax_rate' => 9]);
        $invoice->lines()->create(['description' => 'Export dienst (0%)', 'quantity' => 1, 'unit_price' => 20000, 'tax_rate' => 0]);

        $draft = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => '2026-002',
            'status' => 'draft',
            'date' => now()->subDay(),
            'due_date' => now()->addDays(13),
        ]);
        $draft->lines()->create(['description' => 'Maandelijkse dienst', 'quantity' => 1, 'unit_price' => 50000, 'tax_rate' => 21]);

        if (Account::query()->where('code', '1300')->exists()) {
            app(InvoicePoster::class)->post($invoice->refresh());
        }
    }
}
