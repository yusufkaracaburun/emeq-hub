<?php

namespace App\Console\Commands;

use App\Books\Services\RecurringInvoiceGenerator;
use Illuminate\Console\Command;

class GenerateRecurringInvoices extends Command
{
    protected $signature = 'books:generate-recurring-invoices';

    protected $description = 'Genereer due terugkerende verkoopfacturen (concept) uit de Books-templates.';

    public function handle(RecurringInvoiceGenerator $generator): int
    {
        $count = $generator->generateDue();

        $this->info("{$count} terugkerende factuur(en) gegenereerd.");

        return self::SUCCESS;
    }
}
