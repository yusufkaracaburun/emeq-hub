<?php

namespace Tests\Feature\Books;

use App\Books\Enums\RecurringFrequency;
use App\Books\Enums\RecurringStatus;
use App\Books\Models\BooksCompany;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Books\Models\RecurringInvoice;
use App\Filament\Books\Resources\RecurringInvoices\Pages\CreateRecurringInvoice;
use App\Filament\Books\Resources\RecurringInvoices\Pages\ListRecurringInvoices;
use App\Filament\Books\Resources\RecurringInvoices\RecurringInvoiceResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RecurringInvoiceResourceTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $company = BooksCompany::create(['name' => 'Emeq']);
        config(['books.company_id' => $company->id]);

        Role::firstOrCreate(['name' => 'boekhouder']);
        $boekhouder = User::factory()->create();
        $boekhouder->assignRole('boekhouder');
        $this->actingAs($boekhouder);

        $this->client = Client::create(['name' => 'ACME']);
    }

    public function test_create_recurring_template(): void
    {
        $undo = Repeater::fake();

        Livewire::test(CreateRecurringInvoice::class)
            ->fillForm([
                'client_id' => $this->client->id,
                'frequency' => RecurringFrequency::Monthly->value,
                'status' => RecurringStatus::Active->value,
                'start_date' => Carbon::today()->toDateString(),
                'due_days' => 14,
                'lines' => [
                    ['description' => 'Dienst', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 21],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undo();

        $template = RecurringInvoice::firstOrFail();
        $this->assertSame(1, $template->lines()->count());
        $this->assertTrue($template->next_date->isSameDay(Carbon::today()));
        $this->assertSame(10000, $template->lines()->first()->unit_price);
    }

    public function test_generate_now_action_creates_invoice(): void
    {
        $template = RecurringInvoice::create([
            'client_id' => $this->client->id,
            'status' => RecurringStatus::Active,
            'frequency' => RecurringFrequency::Monthly,
            'start_date' => Carbon::today(),
            'due_days' => 14,
        ]);
        $template->lines()->create([
            'description' => 'Dienst', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21, 'sort' => 0,
        ]);

        Livewire::test(ListRecurringInvoices::class)
            ->callAction('generateDue')
            ->assertHasNoActionErrors();

        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_boekhouder_can_access_staff_cannot(): void
    {
        $this->assertTrue(RecurringInvoiceResource::canAccess());

        Role::firstOrCreate(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->actingAs($staff);

        $this->assertFalse(RecurringInvoiceResource::canAccess());
    }

    public function test_command_runs_generator(): void
    {
        $template = RecurringInvoice::create([
            'client_id' => $this->client->id,
            'status' => RecurringStatus::Active,
            'frequency' => RecurringFrequency::Monthly,
            'start_date' => Carbon::today(),
            'due_days' => 14,
        ]);
        $template->lines()->create([
            'description' => 'Dienst', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21, 'sort' => 0,
        ]);

        $this->artisan('books:generate-recurring-invoices')->assertSuccessful();

        $this->assertSame(1, Invoice::query()->count());
    }
}
