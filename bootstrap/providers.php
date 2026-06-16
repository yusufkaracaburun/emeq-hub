<?php

use App\Providers\AppServiceProvider;
use App\Providers\FeatureServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\SettingsHydrationServiceProvider;

return [
    AppServiceProvider::class,
    FeatureServiceProvider::class,
    AdminPanelProvider::class,
    HorizonServiceProvider::class,
    SettingsHydrationServiceProvider::class,
];
