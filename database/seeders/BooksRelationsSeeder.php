<?php

namespace Database\Seeders;

use App\Books\Models\Client;
use App\Books\Models\Vendor;
use App\Models\Consumer;
use Illuminate\Database\Seeder;

class BooksRelationsSeeder extends Seeder
{
    public function run(): void
    {
        if (Client::query()->doesntExist()) {
            Client::create([
                'consumer_id' => Consumer::query()->value('id'),
                'name' => 'Acme BV',
                'email' => 'finance@acme.test',
                'vat_number' => 'NL000099998B57',
                'city' => 'Amsterdam',
            ]);
            Client::create([
                'name' => 'Bakkerij De Vries',
                'email' => 'info@devries.test',
                'city' => 'Utrecht',
            ]);
        }

        if (Vendor::query()->doesntExist()) {
            Vendor::create([
                'name' => 'Hosting Provider BV',
                'email' => 'billing@hosting.test',
                'coc_number' => '12345678',
                'city' => 'Utrecht',
            ]);
            Vendor::create([
                'name' => 'Kantoorartikelen Jansen',
                'email' => 'sales@jansen.test',
                'city' => 'Rotterdam',
            ]);
        }
    }
}
