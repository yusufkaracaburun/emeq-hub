{{-- ListConsumers custom view: voegt PAT-token-alert toe boven de standaard ListRecords-content. --}}
<x-filament-panels::page>
    @if ($this->lastIssuedPat !== null)
        <x-filament::section icon="heroicon-o-key" icon-color="warning">
            <x-slot name="heading">
                PAT uitgegeven — {{ $this->lastIssuedPat['name'] }}
            </x-slot>

            <x-slot name="description">
                Eenmalig zichtbaar — kopieer dit token nu naar je wachtwoordmanager. Na sluiten kun je 'm niet meer opvragen.
            </x-slot>

            <x-slot name="headerEnd">
                <x-filament::button
                    color="gray"
                    size="sm"
                    icon="heroicon-o-x-mark"
                    icon-position="before"
                    wire:click="dismissIssuedPat"
                >
                    Sluiten
                </x-filament::button>
            </x-slot>

            <div
                x-data="{
                    token: @js($this->lastIssuedPat['token']),
                    copied: false,
                    copy() {
                        navigator.clipboard.writeText(this.token).then(() => {
                            this.copied = true;
                            setTimeout(() => { this.copied = false; }, 2000);
                        });
                    },
                }"
                class="space-y-3"
            >
                <div class="flex items-stretch gap-2">
                    <code
                        class="flex-1 select-all break-all rounded-lg bg-gray-50 px-3 py-2 font-mono text-xs text-gray-900 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/10"
                        x-text="token"
                    ></code>
                    <x-filament::button color="warning" size="md" icon="heroicon-o-clipboard-document" x-on:click="copy()">
                        <span x-show="!copied">Kopieer</span>
                        <span x-show="copied" x-cloak>Gekopieerd</span>
                    </x-filament::button>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Test:
                    <code class="font-mono">curl -H "Authorization: Bearer &lt;token&gt;" {{ config('app.url') }}/v1/ping</code>
                </p>
            </div>
        </x-filament::section>
    @endif

    {{ $this->content }}
</x-filament-panels::page>
