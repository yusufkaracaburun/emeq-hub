{{-- Plan 08-05 — Live koppel-status-widget (D-06, UI-SPEC §S3 regel 197-199). --}}
{{-- Verwacht: $provider (string), $accountStatus (Collection van {account, connection, status}). --}}
{{-- Status-kleuren + Heroicon-keys uit UI-SPEC §Color regel 116-124. --}}
<section class="rounded-lg border border-gray-200 p-6 my-8">
    <h3 class="text-xl font-semibold leading-tight mb-4">Live koppel-status (dev-omgeving)</h3>

    @if ($accountStatus->isEmpty())
        <p class="text-sm text-gray-500">Geen demo-Accounts &mdash; draai <code>php artisan db:seed</code> eerst.</p>
    @else
        <ul class="space-y-2">
            @foreach ($accountStatus as $entry)
                @php
                    $statusConfig = match ($entry['status']) {
                        'connected' => [
                            'icon' => 'check-circle',
                            'text' => 'gekoppeld',
                            'classes' => 'text-emerald-600 bg-emerald-50',
                        ],
                        'pending' => [
                            'icon' => 'clock',
                            'text' => 'pending — wacht op OAuth-callback',
                            'classes' => 'text-amber-600 bg-amber-50',
                        ],
                        'revoked' => [
                            'icon' => 'x-circle',
                            'text' => 'revoked at '.optional($entry['connection']?->revoked_at)->format('Y-m-d H:i'),
                            'classes' => 'text-rose-600 bg-rose-50',
                        ],
                        default => [
                            'icon' => 'minus-circle',
                            'text' => 'nog niet gekoppeld',
                            'classes' => 'text-gray-500 bg-gray-50',
                        ],
                    };
                    $expiresAt = optional($entry['connection']?->expires_at)->format('Y-m-d H:i');
                @endphp
                <li class="flex items-center gap-2 px-3 py-2 rounded {{ $statusConfig['classes'] }}"
                    data-status="{{ $entry['status'] }}"
                    data-icon="{{ $statusConfig['icon'] }}">
                    <x-dynamic-component :component="'heroicon-o-'.$statusConfig['icon']" class="h-5 w-5" aria-hidden="true" />
                    <span class="sr-only">Status: {{ $statusConfig['text'] }}</span>
                    <span class="text-sm font-medium">
                        {{ $entry['account']->display_name }}: {{ ucfirst($provider) }} {{ $statusConfig['text'] }}
                        @if ($entry['status'] === 'connected' && $expiresAt)
                            &mdash; expires {{ $expiresAt }}
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
