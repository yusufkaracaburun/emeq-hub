<x-filament-panels::page>
    @php
        $euro = fn (int $cents): string => '€ '.number_format($cents / 100, 2, ',', '.');

        $verschuldigd = $declaration['verschuldigd'];
        $voorbelasting = $declaration['voorbelasting'];
        $saldo = $declaration['saldo'];
        $terugvragen = $saldo < 0;

        $labels = [
            '1a' => 'Hoog tarief (21%)',
            '1b' => 'Laag tarief (9%)',
            '1e' => '0% / niet bij u belast',
        ];
    @endphp

    {{-- Filterbalk --}}
    <div class="flex flex-col gap-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:flex-row sm:items-end sm:justify-between dark:bg-gray-900 dark:ring-white/10">
        <div class="flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                Van
                <input type="date" wire:model.live="startDate"
                    class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:[color-scheme:dark]" />
            </label>
            <label class="flex flex-col gap-1 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                Tot
                <input type="date" wire:model.live="endDate"
                    class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:[color-scheme:dark]" />
            </label>
        </div>

        <div class="flex flex-wrap gap-1.5">
            @foreach (['quarter' => 'Dit kwartaal', 'prev_quarter' => 'Vorig kwartaal', 'year' => 'Dit jaar'] as $key => $label)
                <button type="button" wire:click="setRange('{{ $key }}')"
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-600 ring-1 ring-inset ring-gray-300 transition hover:bg-gray-50 hover:text-gray-900 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5 dark:hover:text-white">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- KPI-kaarten --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {{-- Saldo: de hero-kaart --}}
        <div class="relative overflow-hidden rounded-2xl p-5 shadow-sm ring-1 {{ $terugvragen ? 'bg-primary-600 ring-primary-600' : 'bg-rose-600 ring-rose-600' }}">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-white/80">{{ $terugvragen ? 'Terug te vragen' : 'Te betalen' }}</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-white/15 text-white">
                    @svg($terugvragen ? 'heroicon-o-arrow-down-tray' : 'heroicon-o-banknotes', 'h-5 w-5')
                </span>
            </div>
            <p class="mt-3 text-3xl font-bold tracking-tight tabular-nums text-white">{{ $euro(abs($saldo)) }}</p>
            <p class="mt-1 text-sm text-white/70">{{ $startDate }} t/m {{ $endDate }}</p>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Verschuldigd (5a)</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400">
                    @svg('heroicon-o-arrow-up-tray', 'h-5 w-5')
                </span>
            </div>
            <p class="mt-3 text-2xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">{{ $euro($verschuldigd) }}</p>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Voorbelasting (5b)</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                    @svg('heroicon-o-receipt-percent', 'h-5 w-5')
                </span>
            </div>
            <p class="mt-3 text-2xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">{{ $euro($voorbelasting) }}</p>
        </div>
    </div>

    {{-- Rubriek 1 --}}
    <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <header class="flex items-center gap-3 border-b border-gray-100 px-6 py-4 dark:border-white/10">
            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                @svg('heroicon-o-document-chart-bar', 'h-5 w-5')
            </span>
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Rubriek 1 — Prestaties binnenland</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $startDate }} t/m {{ $endDate }}</p>
            </div>
        </header>

        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    <th class="px-6 py-2 text-left font-medium">Nr</th>
                    <th class="px-6 py-2 text-left font-medium">Omschrijving</th>
                    <th class="px-6 py-2 text-right font-medium">Grondslag</th>
                    <th class="px-6 py-2 text-right font-medium">Omzetbelasting</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($declaration['rubrieken'] as $code => $rubriek)
                    <tr class="border-t border-gray-50 dark:border-white/5">
                        <td class="px-6 py-3 font-mono text-gray-400">{{ $code }}</td>
                        <td class="px-6 py-3 text-gray-700 dark:text-gray-200">{{ $labels[$code] ?? $code }}</td>
                        <td class="px-6 py-3 text-right tabular-nums text-gray-950 dark:text-white">{{ $euro($rubriek['grondslag']) }}</td>
                        <td class="px-6 py-3 text-right tabular-nums text-gray-950 dark:text-white">{{ $euro($rubriek['btw']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p class="border-t border-gray-100 px-6 py-3 text-xs text-gray-400 dark:border-white/10">
            Rubriek 2/3/4 (verlegd, EU, buitenland) niet van toepassing — geen EU-/verlegd-administratie.
        </p>
    </section>
</x-filament-panels::page>
