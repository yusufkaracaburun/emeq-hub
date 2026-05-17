{{-- Plan 08-02 — OnboardConsumer Filament Page view.

Rendert het Wizard-form via {{ $this->form }}. Geen Cache-flash-blok hier —
plain-token + plain webhook_callback_secret worden via redirect naar
ListConsumers geflashed; daar handelt de bestaande Cache::pull-banner het
eenmalig-tonen af (consistent met Phase-9 Issue-PAT-pattern). --}}
<x-filament-panels::page>
    <form wire:submit="submit">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
