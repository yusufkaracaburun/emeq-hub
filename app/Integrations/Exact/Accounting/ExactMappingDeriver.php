<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Accounting;

use App\Accounting\Enums\TaxTreatment;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use Illuminate\Support\Collection;

final class ExactMappingDeriver
{
    public function deriveAndStore(Connection $connection): void
    {
        $refs = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->get();

        $derived = [
            'vat_codes' => $this->deriveVat($refs),
            'journals' => $this->deriveJournals($refs),
            'gl_accounts' => $this->deriveGl($refs),
        ];

        $metadata = $connection->metadata ?? [];
        $existing = $metadata['accounting_mapping'] ?? [];

        foreach ($derived as $section => $map) {
            $existing[$section] = ($existing[$section] ?? []) + $map;
        }

        $metadata['accounting_mapping'] = $existing;
        $connection->metadata = $metadata;
        $connection->save();
    }

    /**
     * @param  Collection<int, ConnectionAccountingRef>  $refs
     * @return array<string, string>
     */
    private function deriveVat(Collection $refs): array
    {
        $vat = $refs->where('kind', ConnectionAccountingRef::KIND_VAT);
        $out = [];

        foreach (['21', '9', '0'] as $rate) {
            $matches = $vat->filter(fn (ConnectionAccountingRef $r) => (float) ($r->attrs['percentage'] ?? -1) === (float) $rate);

            if ($matches->isEmpty()) {
                continue;
            }

            $preferred = $matches->first(fn (ConnectionAccountingRef $r) => $this->isPlainExclusive((string) $r->label)) ?? $matches->first();

            $out[$rate] = $preferred->code;
        }

        foreach (['21', '9'] as $rate) {
            $verlegd = $vat->first(fn (ConnectionAccountingRef $r) => (float) ($r->attrs['percentage'] ?? -1) === (float) $rate
                && str_contains(mb_strtolower((string) $r->label), 'verlegd'));

            if ($verlegd !== null) {
                $out[TaxTreatment::ReverseCharge->vatCodeKey($rate)] = $verlegd->code;
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, ConnectionAccountingRef>  $refs
     * @return array<string, string>
     */
    private function deriveJournals(Collection $refs): array
    {
        $journals = $refs->where('kind', ConnectionAccountingRef::KIND_JOURNAL)->sortBy('code');
        $out = [];

        $sales = $journals->first(fn (ConnectionAccountingRef $r) => (int) ($r->attrs['type'] ?? 0) === 20);
        $purchase = $journals->first(fn (ConnectionAccountingRef $r) => (int) ($r->attrs['type'] ?? 0) === 22);

        if ($sales !== null) {
            $out['sales'] = $sales->code;
        }

        if ($purchase !== null) {
            $out['purchase'] = $purchase->code;
        }

        return $out;
    }

    /**
     * @param  Collection<int, ConnectionAccountingRef>  $refs
     * @return array<string, string>
     */
    private function deriveGl(Collection $refs): array
    {
        $gl = $refs->where('kind', ConnectionAccountingRef::KIND_GL)->sortBy('code');
        $out = [];

        $omzet = $gl->first(fn (ConnectionAccountingRef $r) => str_starts_with($r->code, '8'));
        $kosten = $gl->first(fn (ConnectionAccountingRef $r) => str_starts_with($r->code, '4'));

        if ($omzet !== null) {
            $out['omzet'] = $omzet->code;
            $out['sales_default'] = $omzet->code;
        }

        if ($kosten !== null) {
            $out['kosten'] = $kosten->code;
            $out['purchase_default'] = $kosten->code;
            $out['_default'] = $kosten->code;
        } elseif ($omzet !== null) {
            $out['_default'] = $omzet->code;
        }

        return $out;
    }

    private function isPlainExclusive(string $label): bool
    {
        $label = mb_strtolower($label);

        return str_contains($label, 'excl') && ! str_contains($label, 'verlegd') && ! str_contains($label, 'incl ');
    }
}
