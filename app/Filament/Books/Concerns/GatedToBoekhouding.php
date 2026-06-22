<?php

declare(strict_types=1);

namespace App\Filament\Books\Concerns;

/*
 * Functiescheiding voor de Boekhouding-cluster binnen het admin-paneel: enkel
 * super-admin en boekhouder zien/bereiken de boekhoud-resources. Gedeeld door de
 * cluster, de resources en de Overzichten-page (Filament re-runt canAccess elke
 * Livewire-request).
 */
trait GatedToBoekhouding
{
    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'boekhouder']) ?? false;
    }
}
