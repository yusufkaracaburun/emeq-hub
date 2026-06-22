<?php

namespace Tests\Feature\Books;

use App\Books\Models\Account;
use App\Books\Models\Bill;
use App\Books\Models\BooksCompany;
use App\Books\Models\Vendor;
use App\Filament\Books\Resources\Bills\Pages\CreateBill;
use App\Filament\Books\Resources\Bills\Pages\ListBills;
use App\Models\User;
use Database\Seeders\BooksChartSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BillResourceTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private int $costAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $company = BooksCompany::create(['name' => 'Emeq']);
        config(['books.company_id' => $company->id]);

        $this->seed(BooksChartSeeder::class);

        Role::firstOrCreate(['name' => 'boekhouder']);
        $boekhouder = User::factory()->create();
        $boekhouder->assignRole('boekhouder');
        $this->actingAs($boekhouder);

        $this->vendor = Vendor::create(['name' => 'Hosting Provider BV']);
        $this->costAccountId = (int) Account::where('code', '4400')->value('id');
    }

    public function test_line_observer_computes_line_and_bill_totals(): void
    {
        $bill = Bill::create([
            'vendor_id' => $this->vendor->id,
            'status' => 'draft',
            'date' => now(),
        ]);

        $bill->lines()->create(['account_id' => $this->costAccountId, 'description' => 'Hosting', 'quantity' => 2, 'unit_price' => 10000, 'tax_rate' => 21]);
        $bill->lines()->create(['account_id' => $this->costAccountId, 'description' => 'Brandstof', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 9]);

        $line = $bill->lines()->where('description', 'Hosting')->firstOrFail();
        $this->assertSame(20000, $line->subtotal);
        $this->assertSame(4200, $line->tax_amount);
        $this->assertSame(24200, $line->total);

        $bill->refresh();
        $this->assertSame(25000, $bill->subtotal);
        $this->assertSame(4650, $bill->tax_total);
        $this->assertSame(29650, $bill->total);
    }

    public function test_deleting_a_line_recalculates_totals(): void
    {
        $bill = Bill::create(['vendor_id' => $this->vendor->id, 'status' => 'draft', 'date' => now()]);
        $line = $bill->lines()->create(['account_id' => $this->costAccountId, 'description' => 'X', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);

        $bill->refresh();
        $this->assertSame(12100, $bill->total);

        $line->delete();

        $bill->refresh();
        $this->assertSame(0, $bill->total);
    }

    public function test_create_bill_via_form_converts_euros_and_computes_totals(): void
    {
        Livewire::test(CreateBill::class)
            ->fillForm([
                'vendor_id' => $this->vendor->id,
                'bill_number' => 'INK-2026-001',
                'status' => 'draft',
                'date' => now()->toDateString(),
                'lines' => [
                    ['account_id' => $this->costAccountId, 'description' => 'Hosting', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 21],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $bill = Bill::firstOrFail();

        // Stukprijs 100 euro → 10000 centen; 2 × → subtotaal 20000, BTW 4200, totaal 24200.
        $this->assertSame(1, $bill->lines()->count());
        $this->assertSame(20000, $bill->subtotal);
        $this->assertSame(4200, $bill->tax_total);
        $this->assertSame(24200, $bill->total);
        $this->assertSame($this->costAccountId, $bill->lines()->first()->account_id);
    }

    public function test_list_page_shows_bill(): void
    {
        $bill = Bill::create([
            'vendor_id' => $this->vendor->id,
            'bill_number' => 'INK-2026-009',
            'status' => 'draft',
            'date' => now(),
        ]);

        Livewire::test(ListBills::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$bill]);
    }
}
