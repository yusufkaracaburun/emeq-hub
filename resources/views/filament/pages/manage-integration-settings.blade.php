<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6" style="display: flex; justify-content: flex-end;">
            <x-filament::button type="submit" icon="heroicon-m-check">
                Opslaan
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
