@props([
    'title',
    'lines',
    'total',
    'euro',
    'empty' => null,
])

<div {{ $attributes }}>
    <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $title }}</p>

    <div class="divide-y divide-gray-100 dark:divide-white/5">
        @forelse ($lines as $line)
            <div class="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-1.5 transition hover:bg-gray-50 dark:hover:bg-white/5">
                <div class="flex min-w-0 items-baseline gap-3">
                    <span class="w-10 shrink-0 font-mono text-xs text-gray-400 dark:text-gray-500">{{ $line['code'] }}</span>
                    <span class="truncate text-gray-700 dark:text-gray-300">{{ $line['name'] }}</span>
                </div>
                <span class="shrink-0 tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($line['amount']) }}</span>
            </div>
        @empty
            <p class="py-1.5 text-gray-400 dark:text-gray-500">{{ $empty }}</p>
        @endforelse
    </div>

    <div class="mt-2 flex items-center justify-between border-t border-gray-200 pt-2 dark:border-white/10">
        <span class="font-semibold text-gray-900 dark:text-gray-100">Totaal {{ \Illuminate\Support\Str::lower($title) }}</span>
        <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($total) }}</span>
    </div>
</div>
