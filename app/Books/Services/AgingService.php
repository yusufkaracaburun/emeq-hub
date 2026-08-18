<?php

declare(strict_types=1);

namespace App\Books\Services;

use App\Books\Enums\BillStatus;
use App\Books\Enums\InvoiceStatus;
use App\Books\Models\Bill;
use App\Books\Models\Invoice;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AgingService
{
    /** @var array<string, string> bucket-key => label */
    public const BUCKETS = [
        'current' => 'Niet vervallen',
        'd1_30' => '1–30 dagen',
        'd31_60' => '31–60 dagen',
        'd61_90' => '61–90 dagen',
        'd90_plus' => '> 90 dagen',
    ];

    /** @return array{as_of: string, kind: string, rows: list<array{relation: string, buckets: array<string, int>, total: int}>, totals: array<string, int>} */
    public function receivables(string $asOf): array
    {
        $invoices = Invoice::query()
            ->with(['client', 'payments'])
            ->where('status', '!=', InvoiceStatus::Draft)
            ->whereDate('date', '<=', $asOf)
            ->get();

        return $this->build($invoices, $asOf, 'receivable', fn (Invoice $invoice): string => $invoice->client?->name ?? '— onbekend —');
    }

    /** @return array{as_of: string, kind: string, rows: list<array{relation: string, buckets: array<string, int>, total: int}>, totals: array<string, int>} */
    public function payables(string $asOf): array
    {
        $bills = Bill::query()
            ->with(['vendor', 'payments'])
            ->where('status', '!=', BillStatus::Draft)
            ->whereDate('date', '<=', $asOf)
            ->get();

        return $this->build($bills, $asOf, 'payable', fn (Bill $bill): string => $bill->vendor?->name ?? '— onbekend —');
    }

    /**
     * @param  Collection<int, Invoice|Bill>  $documents
     * @param  Closure(Model): string  $relationName
     * @return array{as_of: string, kind: string, rows: list<array{relation: string, buckets: array<string, int>, total: int}>, totals: array<string, int>}
     */
    private function build(Collection $documents, string $asOf, string $kind, Closure $relationName): array
    {
        $asOfDate = Carbon::parse($asOf)->startOfDay();
        $emptyBuckets = array_fill_keys(array_keys(self::BUCKETS), 0);

        $byRelation = [];

        foreach ($documents as $document) {
            $amountDue = $document->amountDue();

            if ($amountDue <= 0) {
                continue;
            }

            $name = $relationName($document);
            $bucket = $this->bucketKey($asOfDate, $document);

            $byRelation[$name] ??= ['relation' => $name, 'buckets' => $emptyBuckets, 'total' => 0];
            $byRelation[$name]['buckets'][$bucket] += $amountDue;
            $byRelation[$name]['total'] += $amountDue;
        }

        $rows = array_values($byRelation);
        usort($rows, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $totals = $emptyBuckets;
        $grand = 0;

        foreach ($rows as $row) {
            foreach ($row['buckets'] as $key => $value) {
                $totals[$key] += $value;
            }

            $grand += $row['total'];
        }

        return [
            'as_of' => $asOfDate->toDateString(),
            'kind' => $kind,
            'rows' => $rows,
            'totals' => $totals + ['total' => $grand],
        ];
    }

    private function bucketKey(Carbon $asOf, Model $document): string
    {
        $due = Carbon::parse($document->due_date ?? $document->date)->startOfDay();
        $daysOverdue = (int) round($due->diffInDays($asOf, false));

        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => 'd1_30',
            $daysOverdue <= 60 => 'd31_60',
            $daysOverdue <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }
}
