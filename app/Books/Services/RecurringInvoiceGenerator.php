<?php

namespace App\Books\Services;

use App\Books\Enums\InvoiceStatus;
use App\Books\Enums\RecurringStatus;
use App\Books\Models\Invoice;
use App\Books\Models\RecurringInvoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecurringInvoiceGenerator
{
    private const MAX_CATCHUP = 24;

    public function generateDue(?CarbonInterface $asOf = null): int
    {
        $today = ($asOf ?? Carbon::today())->copy()->startOfDay();

        return RecurringInvoice::query()
            ->where('status', RecurringStatus::Active)
            ->whereDate('next_date', '<=', $today)
            ->get()
            ->sum(fn (RecurringInvoice $template): int => $this->generateForTemplate($template, $today));
    }

    private function generateForTemplate(RecurringInvoice $template, CarbonInterface $today): int
    {
        $generated = 0;

        for ($i = 0; $i < self::MAX_CATCHUP; $i++) {
            if ($template->status !== RecurringStatus::Active) {
                break;
            }

            $next = $template->next_date;

            if ($next === null || $next->gt($today)) {
                break;
            }

            if ($template->end_date !== null && $next->gt($template->end_date)) {
                $template->status = RecurringStatus::Ended;
                $template->save();
                break;
            }

            $this->createInvoice($template, $next);
            $generated++;

            $advanced = $template->frequency->nextDate($next);
            $template->occurrences_count++;
            $template->next_date = $advanced;

            if ($template->hasReachedEnd($advanced)) {
                $template->status = RecurringStatus::Ended;
            }

            $template->save();
        }

        return $generated;
    }

    private function createInvoice(RecurringInvoice $template, CarbonInterface $date): Invoice
    {
        return DB::transaction(function () use ($template, $date): Invoice {
            $invoice = Invoice::create([
                'company_id' => $template->company_id,
                'client_id' => $template->client_id,
                'status' => InvoiceStatus::Draft,
                'date' => $date,
                'due_date' => $date->copy()->addDays($template->due_days),
                'notes' => $template->notes,
            ]);

            foreach ($template->lines()->orderBy('sort')->get() as $line) {
                $invoice->lines()->create([
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'tax_rate' => $line->tax_rate,
                    'sort' => $line->sort,
                ]);
            }

            return $invoice->refresh();
        });
    }
}
