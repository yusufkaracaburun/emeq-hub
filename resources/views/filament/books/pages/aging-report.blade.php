<x-filament-panels::page>
    @php
        $euro = fn (int $cents): string => '€ '.number_format($cents / 100, 2, ',', '.');

        $rows = $report['rows'];
        $totals = $report['totals'];
        $grand = $totals['total'];
        $isPayable = $report['kind'] === 'payable';
        $relationHeader = $isPayable ? 'Leverancier' : 'Klant';
        $overdue = $grand - ($totals['current'] ?? 0);
    @endphp

    {{-- Filterbalk --}}
    <div class="flex flex-col gap-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:flex-row sm:items-end sm:justify-between dark:bg-gray-900 dark:ring-white/10">
        <div class="flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                Peildatum
                <input type="date" wire:model.live="asOfDate"
                    class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:[color-scheme:dark]" />
            </label>

            <div class="flex flex-wrap gap-1.5">
                @foreach (['today' => 'Vandaag', 'end_prev_month' => 'Eind vorige maand', 'end_prev_quarter' => 'Eind vorig kwartaal'] as $key => $label)
                    <button type="button" wire:click="setAsOf('{{ $key }}')"
                        class="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-600 ring-1 ring-inset ring-gray-300 transition hover:bg-gray-50 hover:text-gray-900 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5 dark:hover:text-white">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Debiteuren / crediteuren-schakelaar --}}
        <div class="inline-flex rounded-lg bg-gray-100 p-1 dark:bg-white/5">
            @foreach (['receivable' => 'Debiteuren', 'payable' => 'Crediteuren'] as $key => $label)
                <button type="button" wire:click="setKind('{{ $key }}')"
                    @class([
                        'rounded-md px-3 py-1.5 text-xs font-semibold transition',
                        'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white' => $kind === $key,
                        'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' => $kind !== $key,
                    ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- KPI-kaarten --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <div class="relative overflow-hidden rounded-2xl bg-primary-600 p-5 shadow-sm ring-1 ring-primary-600">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-white/80">Totaal openstaand</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-white/15 text-white">
                    @svg('heroicon-o-banknotes', 'h-5 w-5')
                </span>
            </div>
            <p class="mt-3 text-3xl font-bold tracking-tight tabular-nums text-white">{{ $euro($grand) }}</p>
            <p class="mt-1 text-sm text-white/70">{{ $relationHeader }}en · per {{ \Illuminate\Support\Carbon::parse($report['as_of'])->format('d-m-Y') }}</p>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Vervallen</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-rose-50 text-rose-600 dark:bg-rose-400/10 dark:text-rose-400">
                    @svg('heroicon-o-exclamation-triangle', 'h-5 w-5')
                </span>
            </div>
            <p class="mt-3 text-2xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">{{ $euro($overdue) }}</p>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">> 90 dagen</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400">
                    @svg('heroicon-o-clock', 'h-5 w-5')
                </span>
            </div>
            <p class="mt-3 text-2xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">{{ $euro($totals['d90_plus'] ?? 0) }}</p>
        </div>
    </div>

    {{-- Aging-matrix --}}
    <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <header class="flex items-center gap-3 border-b border-gray-100 px-6 py-4 dark:border-white/10">
            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                @svg('heroicon-o-table-cells', 'h-5 w-5')
            </span>
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Ouderdom per {{ strtolower($relationHeader) }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Openstaande posten gebucket op vervaldatum t.o.v. de peildatum</p>
            </div>
        </header>

        @if (count($rows) === 0)
            <div class="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                Geen openstaande posten op deze peildatum.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            <th class="px-6 py-2 text-left font-medium">{{ $relationHeader }}</th>
                            @foreach ($buckets as $label)
                                <th class="px-4 py-2 text-right font-medium">{{ $label }}</th>
                            @endforeach
                            <th class="px-6 py-2 text-right font-semibold text-gray-700 dark:text-gray-200">Totaal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-50 dark:border-white/5">
                                <td class="px-6 py-3 font-medium text-gray-950 dark:text-white">{{ $row['relation'] }}</td>
                                @foreach (array_keys($buckets) as $bucketKey)
                                    <td @class([
                                        'px-4 py-3 text-right tabular-nums',
                                        'text-gray-400 dark:text-gray-600' => ($row['buckets'][$bucketKey] ?? 0) === 0,
                                        'text-rose-600 dark:text-rose-400 font-medium' => $bucketKey === 'd90_plus' && ($row['buckets'][$bucketKey] ?? 0) > 0,
                                        'text-gray-700 dark:text-gray-200' => $bucketKey !== 'd90_plus' && ($row['buckets'][$bucketKey] ?? 0) > 0,
                                    ])>
                                        {{ ($row['buckets'][$bucketKey] ?? 0) === 0 ? '—' : $euro($row['buckets'][$bucketKey]) }}
                                    </td>
                                @endforeach
                                <td class="px-6 py-3 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ $euro($row['total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                            <td class="px-6 py-3 font-semibold text-gray-950 dark:text-white">Totaal</td>
                            @foreach (array_keys($buckets) as $bucketKey)
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ $euro($totals[$bucketKey] ?? 0) }}</td>
                            @endforeach
                            <td class="px-6 py-3 text-right font-bold tabular-nums text-gray-950 dark:text-white">{{ $euro($grand) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </section>
</x-filament-panels::page>
