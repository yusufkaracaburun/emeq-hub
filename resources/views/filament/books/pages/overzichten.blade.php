<x-filament-panels::page>
    @php
        $euro = fn (int $cents): string => '€ '.number_format($cents / 100, 2, ',', '.');

        $revenue = $profitAndLoss['total_revenue'];
        $expense = $profitAndLoss['total_expense'];
        $result = $profitAndLoss['result'];
        $margin = $revenue > 0 ? round($result / $revenue * 100) : 0;
        $resultPositive = $result >= 0;
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
            @foreach (['month' => 'Deze maand', 'quarter' => 'Dit kwartaal', 'year' => 'Dit jaar', 'prev_year' => 'Vorig jaar'] as $key => $label)
                <button type="button" wire:click="setRange('{{ $key }}')"
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-600 ring-1 ring-inset ring-gray-300 transition hover:bg-gray-50 hover:text-gray-900 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5 dark:hover:text-white">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- KPI-kaarten --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $cards = [
                ['label' => 'Omzet', 'value' => $euro($revenue), 'icon' => 'heroicon-o-arrow-trending-up', 'tint' => 'text-primary-600 bg-primary-50 dark:text-primary-400 dark:bg-primary-400/10'],
                ['label' => 'Kosten', 'value' => $euro($expense), 'icon' => 'heroicon-o-arrow-trending-down', 'tint' => 'text-amber-600 bg-amber-50 dark:text-amber-400 dark:bg-amber-400/10'],
                ['label' => 'Marge', 'value' => $margin.'%', 'icon' => 'heroicon-o-chart-pie', 'tint' => 'text-gray-600 bg-gray-100 dark:text-gray-300 dark:bg-white/10'],
            ];
        @endphp

        {{-- Resultaat: de hero-kaart --}}
        <div class="relative overflow-hidden rounded-2xl p-5 shadow-sm ring-1 {{ $resultPositive ? 'bg-primary-600 ring-primary-600' : 'bg-rose-600 ring-rose-600' }}">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-white/80">Resultaat</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-white/15 text-white">
                    @svg($resultPositive ? 'heroicon-o-banknotes' : 'heroicon-o-exclamation-triangle', 'h-5 w-5')
                </span>
            </div>
            <p class="mt-3 text-3xl font-bold tracking-tight tabular-nums text-white">{{ $euro($result) }}</p>
            <p class="mt-1 text-sm text-white/70">{{ $startDate }} t/m {{ $endDate }}</p>
        </div>

        @foreach ($cards as $card)
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                    <span class="flex h-9 w-9 items-center justify-center rounded-full {{ $card['tint'] }}">
                        @svg($card['icon'], 'h-5 w-5')
                    </span>
                </div>
                <p class="mt-3 text-2xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">{{ $card['value'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Statements --}}
    <div class="grid gap-6 xl:grid-cols-2">
        {{-- Winst & Verlies --}}
        <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <header class="flex items-center gap-3 border-b border-gray-100 px-6 py-4 dark:border-white/10">
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                    @svg('heroicon-o-document-chart-bar', 'h-5 w-5')
                </span>
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Winst &amp; Verlies</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $startDate }} t/m {{ $endDate }}</p>
                </div>
            </header>

            <div class="px-6 py-5 text-sm">
                <x-books.statement-group title="Omzet" :lines="$profitAndLoss['revenue']" :total="$revenue" :euro="$euro" empty="Geen omzet in deze periode." />
                <x-books.statement-group title="Kosten" :lines="$profitAndLoss['expense']" :total="$expense" :euro="$euro" empty="Geen kosten in deze periode." class="mt-6" />

                <div class="mt-6 flex items-center justify-between rounded-xl px-4 py-3 {{ $resultPositive ? 'bg-primary-50 dark:bg-primary-400/10' : 'bg-rose-50 dark:bg-rose-400/10' }}">
                    <span class="text-sm font-bold text-gray-950 dark:text-white">Resultaat</span>
                    <span class="text-lg font-bold tabular-nums {{ $resultPositive ? 'text-primary-700 dark:text-primary-300' : 'text-rose-700 dark:text-rose-300' }}">{{ $euro($result) }}</span>
                </div>
            </div>
        </section>

        {{-- Balans --}}
        <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <header class="flex items-center justify-between gap-3 border-b border-gray-100 px-6 py-4 dark:border-white/10">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                        @svg('heroicon-o-scale', 'h-5 w-5')
                    </span>
                    <div>
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Balans</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Per {{ $endDate }}</p>
                    </div>
                </div>

                @if ($balanceSheet['balances'])
                    <span class="inline-flex items-center gap-1 rounded-full bg-primary-50 px-2.5 py-1 text-xs font-semibold text-primary-700 dark:bg-primary-400/10 dark:text-primary-300">
                        @svg('heroicon-o-check-circle', 'h-4 w-4') In balans
                    </span>
                @else
                    <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:bg-rose-400/10 dark:text-rose-300">
                        @svg('heroicon-o-exclamation-triangle', 'h-4 w-4') Sluit niet
                    </span>
                @endif
            </header>

            <div class="px-6 py-5 text-sm">
                <x-books.statement-group title="Activa" :lines="$balanceSheet['assets']" :total="$balanceSheet['total_assets']" :euro="$euro" empty="Geen activa." />

                @php
                    $passiva = array_merge(
                        $balanceSheet['liabilities'],
                        $balanceSheet['equity'],
                        [['code' => '', 'name' => 'Resultaat boekjaar', 'amount' => $balanceSheet['result']]],
                    );
                @endphp
                <x-books.statement-group title="Passiva" :lines="$passiva" :total="$balanceSheet['total_liabilities_and_equity']" :euro="$euro" empty="Geen passiva." class="mt-6" />
            </div>
        </section>
    </div>
</x-filament-panels::page>
