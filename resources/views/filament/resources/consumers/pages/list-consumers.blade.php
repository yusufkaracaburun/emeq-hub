{{-- ListConsumers custom view: voegt PAT-token-alert toe boven de standaard ListRecords-content.

D-9 (WR-06): token komt uit een server-side Cache flash (key: pat-flash:{livewire-id}).
Cache::pull() leest + delete't in één call, dus het token zit ÉÉN keer in de
gerenderde HTML en daarna nergens meer — niet in Livewire's wire:snapshot,
niet in een Alpine x-data binding. Bij de volgende page-render is de cache
leeg en het alert verdwijnt vanzelf. --}}
@php
    $issuedToken = \Illuminate\Support\Facades\Cache::pull('pat-flash:'.$this->getId());
    $issuedName = \Illuminate\Support\Facades\Cache::pull('pat-flash-name:'.$this->getId());
@endphp
<x-filament-panels::page>
    @if ($issuedToken !== null)
        <x-filament::section icon="heroicon-o-key" icon-color="warning">
            <x-slot name="heading">
                PAT uitgegeven — {{ $issuedName }}
            </x-slot>

            <x-slot name="description">
                Eenmalig zichtbaar — kopieer dit token nu naar je wachtwoordmanager. Bij de volgende page-load verdwijnt het.
            </x-slot>

            <div
                x-data="{
                    copied: false,
                    copy() {
                        navigator.clipboard.writeText(this.$refs.tokenCode.innerText).then(() => {
                            this.copied = true;
                            setTimeout(() => { this.copied = false; }, 2000);
                        });
                    },
                }"
                class="space-y-3"
            >
                <div class="flex items-stretch gap-2">
                    <code
                        x-ref="tokenCode"
                        class="flex-1 select-all break-all rounded-lg bg-gray-50 px-3 py-2 font-mono text-xs text-gray-900 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/10"
                    >{{ $issuedToken }}</code>
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
