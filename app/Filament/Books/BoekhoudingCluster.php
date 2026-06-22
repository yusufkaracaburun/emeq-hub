<?php

declare(strict_types=1);

namespace App\Filament\Books;

use App\Filament\Books\Concerns\GatedToBoekhouding;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;

/*
 * Boekhouding-sectie van het admin-paneel: bundelt de Books-resources +
 * Overzichten onder /admin/boekhouding/*. Zichtbaar voor super-admin/boekhouder
 * (GatedToBoekhouding); de cluster-nav verbergt zichzelf als geen enkele child
 * toegankelijk is (Cluster::canAccessClusteredComponents).
 */
class BoekhoudingCluster extends Cluster
{
    use GatedToBoekhouding;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $navigationLabel = 'Boekhouding';

    protected static ?string $slug = 'boekhouding';
}
