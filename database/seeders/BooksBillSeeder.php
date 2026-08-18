<?php

namespace Database\Seeders;

use App\Books\Models\Account;
use App\Books\Models\Bill;
use App\Books\Models\Vendor;
use App\Books\Services\BillPoster;
use Illuminate\Database\Seeder;

class BooksBillSeeder extends Seeder
{
    public function run(): void
    {
        if (Bill::query()->exists()) {
            return;
        }

        $this->call(BooksRelationsSeeder::class);

        $vendor = Vendor::query()->firstOrFail();

        $algemeneKosten = Account::query()->where('code', '4400')->value('id');
        $autokosten = Account::query()->where('code', '4500')->value('id');

        $bill = Bill::create([
            'vendor_id' => $vendor->id,
            'bill_number' => 'INK-2026-001',
            'status' => 'received',
            'date' => now()->subDays(3),
            'due_date' => now()->addDays(11),
        ]);
        $bill->lines()->create(['account_id' => $algemeneKosten, 'description' => 'Hosting juni', 'quantity' => 1, 'unit_price' => 12000, 'tax_rate' => 21]);
        $bill->lines()->create(['account_id' => $autokosten, 'description' => 'Brandstof', 'quantity' => 1, 'unit_price' => 6000, 'tax_rate' => 9]);

        $draft = Bill::create([
            'vendor_id' => $vendor->id,
            'bill_number' => 'INK-2026-002',
            'status' => 'draft',
            'date' => now()->subDay(),
            'due_date' => now()->addDays(13),
        ]);
        $draft->lines()->create(['account_id' => $algemeneKosten, 'description' => 'Kantoorartikelen', 'quantity' => 1, 'unit_price' => 8500, 'tax_rate' => 21]);

        if ($algemeneKosten !== null && Account::query()->where('code', '1600')->exists()) {
            app(BillPoster::class)->post($bill->refresh());
        }
    }
}
