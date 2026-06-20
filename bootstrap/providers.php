<?php

use App\Providers\AppServiceProvider;
use App\Providers\FeatureServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\BooksPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\SettingsHydrationServiceProvider;

return [
    AppServiceProvider::class,
    FeatureServiceProvider::class,
    AdminPanelProvider::class,
    BooksPanelProvider::class,
    HorizonServiceProvider::class,
    SettingsHydrationServiceProvider::class,
];
