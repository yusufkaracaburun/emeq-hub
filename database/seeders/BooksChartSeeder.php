<?php

namespace Database\Seeders;

use App\Books\Enums\AccountType;
use App\Books\Models\Account;
use App\Books\Models\AccountSubtype;
use App\Books\Models\BankAccount;
use App\Books\Models\BooksCompany;
use Illuminate\Database\Seeder;

class BooksChartSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = (int) config('books.company_id', 1);

        if (! BooksCompany::whereKey($companyId)->exists()) {
            $company = new BooksCompany(['name' => 'Emeq']);
            $company->id = $companyId;
            $company->save();
        }

        foreach (config('books-chart.accounts') as $row) {
            $type = AccountType::from($row['type']);
            $category = $type->getCategory();

            $subtype = AccountSubtype::firstOrCreate(
                ['name' => $row['subtype'], 'category' => $category, 'type' => $type],
            );

            $account = Account::firstOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'category' => $category,
                    'type' => $type,
                    'subtype_id' => $subtype->id,
                    'currency_code' => config('books.default_currency', 'EUR'),
                ],
            );

            if (($row['bank'] ?? false) && $account->bankAccount()->doesntExist()) {
                BankAccount::create([
                    'account_id' => $account->id,
                    'type' => 'depository',
                    'enabled' => true,
                ]);
            }
        }
    }
}
