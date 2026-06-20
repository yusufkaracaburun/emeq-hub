{{-- ListConsumers custom view: voegt PAT-token-alert toe boven de standaard ListRecords-content.

D-9 (WR-06): token komt uit een server-side Cache flash. Cache::pull() leest + delete't
in één call, dus het token zit ÉÉN keer in de gerenderde HTML en daarna nergens meer —
niet in Livewire's wire:snapshot, niet in een Alpine x-data binding. Bij de volgende
page-render is de cache leeg en het alert verdwijnt vanzelf.

Twee write-paden gebruiken twee verschillende keys:
 1. ConsumerResource::issuePatAction (Action::action()-closure op deze ListConsumers-
    component) → 'pat-flash:'.$livewire->getId() — Livewire-id-scoped want writer +
    reader zijn dezelfde component.
 2. OnboardConsumer-wizard → 'pat-flash:user:'.auth()->id() — user-scoped want writer
    en reader zijn twee verschillende Livewire-componenten (CR-01 fix). Webhook-secret
    volgt hetzelfde user-scope-pattern (CR-02 fix). --}}
@php
    $cache = \Illuminate\Support\Facades\Cache::class;
    $listId = $this->getId();
    $userId = auth()->id();
    // Read both keys; whichever wrote-pad triggerde, één pad heeft data.
    $issuedToken = $cache::pull('pat-flash:'.$listId) ?? $cache::pull('pat-flash:user:'.$userId);
    $issuedName = $cache::pull('pat-flash-name:'.$listId) ?? $cache::pull('pat-flash-name:user:'.$userId);
    $issuedWebhookSecret = $cache::pull('webhook-secret-flash:user:'.$userId);
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
                        const text = this.$refs.tokenCode.innerText;
                        const ok = () => { this.copied = true; setTimeout(() => { this.copied = false; }, 2000); };
                        // navigator.clipboard bestaat alleen in een secure context (HTTPS of localhost);
                        // op http://hub.emeq.test is dat undefined → val terug op execCommand.
                        if (navigator.clipboard && window.isSecureContext) {
                            navigator.clipboard.writeText(text).then(ok).catch(() => this.fallbackCopy(text, ok));
                        } else {
                            this.fallbackCopy(text, ok);
                        }
                    },
                    fallbackCopy(text, ok) {
                        const ta = document.createElement('textarea');
                        ta.value = text;
                        ta.style.position = 'fixed';
                        ta.style.top = '0';
                        ta.style.opacity = '0';
                        document.body.appendChild(ta);
                        ta.focus();
                        ta.select();
                        try { document.execCommand('copy'); ok(); } catch (e) {}
                        ta.remove();
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

    @if ($issuedWebhookSecret !== null)
        <x-filament::section icon="heroicon-o-shield-check" icon-color="warning">
            <x-slot name="heading">
                Webhook callback-secret
            </x-slot>

            <x-slot name="description">
                Eenmalig zichtbaar — geef dit secret door aan de Consumer-app. Daarna alleen rotate-able.
            </x-slot>

            <div
                x-data="{
                    copied: false,
                    copy() {
                        const text = this.$refs.secretCode.innerText;
                        const ok = () => { this.copied = true; setTimeout(() => { this.copied = false; }, 2000); };
                        if (navigator.clipboard && window.isSecureContext) {
                            navigator.clipboard.writeText(text).then(ok).catch(() => this.fallbackCopy(text, ok));
                        } else {
                            this.fallbackCopy(text, ok);
                        }
                    },
                    fallbackCopy(text, ok) {
                        const ta = document.createElement('textarea');
                        ta.value = text;
                        ta.style.position = 'fixed';
                        ta.style.top = '0';
                        ta.style.opacity = '0';
                        document.body.appendChild(ta);
                        ta.focus();
                        ta.select();
                        try { document.execCommand('copy'); ok(); } catch (e) {}
                        ta.remove();
                    },
                }"
                class="space-y-3"
            >
                <div class="flex items-stretch gap-2">
                    <code
                        x-ref="secretCode"
                        class="flex-1 select-all break-all rounded-lg bg-gray-50 px-3 py-2 font-mono text-xs text-gray-900 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/10"
                    >{{ $issuedWebhookSecret }}</code>
                    <x-filament::button color="warning" size="md" icon="heroicon-o-clipboard-document" x-on:click="copy()">
                        <span x-show="!copied">Kopieer</span>
                        <span x-show="copied" x-cloak>Gekopieerd</span>
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif

    {{ $this->content }}
</x-filament-panels::page>
