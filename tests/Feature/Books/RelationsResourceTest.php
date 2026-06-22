<?php

namespace Tests\Feature\Books;

use App\Books\Models\BooksCompany;
use App\Books\Models\Client;
use App\Books\Models\Vendor;
use App\Filament\Books\Resources\Clients\Pages\CreateClient;
use App\Filament\Books\Resources\Clients\Pages\ListClients;
use App\Filament\Books\Resources\Vendors\Pages\CreateVendor;
use App\Filament\Books\Resources\Vendors\Pages\ListVendors;
use App\Models\Consumer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RelationsResourceTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_create_client_linked_to_consumer(): void
    {
        $consumer = Consumer::factory()->create();

        Livewire::test(CreateClient::class)
            ->fillForm([
                'consumer_id' => $consumer->id,
                'name' => 'Acme BV',
                'email' => 'finance@acme.test',
                'vat_number' => 'NL000099998B57',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $client = Client::firstOrFail();
        $this->assertSame('Acme BV', $client->name);
        $this->assertSame((int) config('books.company_id'), $client->company_id);
        $this->assertTrue($client->consumer->is($consumer));
    }

    public function test_list_page_shows_clients(): void
    {
        $client = Client::create(['name' => 'Acme BV']);

        Livewire::test(ListClients::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$client]);
    }

    public function test_create_vendor(): void
    {
        Livewire::test(CreateVendor::class)
            ->fillForm([
                'name' => 'Hosting BV',
                'email' => 'billing@host.test',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Vendor::count());
    }

    public function test_list_page_shows_vendors(): void
    {
        $vendor = Vendor::create(['name' => 'Hosting BV']);

        Livewire::test(ListVendors::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$vendor]);
    }
}
