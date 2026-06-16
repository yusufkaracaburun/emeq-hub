<?php

use App\Providers\AppServiceProvider;
use App\Providers\FeatureServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    FeatureServiceProvider::class,
    AdminPanelProvider::class,
    HorizonServiceProvider::class,
];
