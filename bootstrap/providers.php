<?php

use App\Providers\AppServiceProvider;
use App\Providers\FeatureServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    FeatureServiceProvider::class,
    AdminPanelProvider::class,
];
