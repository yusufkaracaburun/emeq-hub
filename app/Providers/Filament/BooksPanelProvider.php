<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 * Boekhoud-paneel ("Books") — emeq's eigen boekhouding, los van het admin-paneel.
 * Eigen bounded context (ERPSAAS-gebaseerd, zie .docs/decisions/erpsaas-books-module.md);
 * raakt de consumer-integratielaag niet. Toegang via rol `boekhouder`/`super-admin`
 * (User::canAccessPanel). NIET ->default() — dat blijft het admin-paneel.
 */
class BooksPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('books')
            ->path('boekhouding')
            ->login()
            ->authGuard('web')
            ->brandName('Emeq Boekhouding')
            ->favicon(asset('favicon.ico'))
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->discoverResources(in: app_path('Filament/Books/Resources'), for: 'App\Filament\Books\Resources')
            ->discoverPages(in: app_path('Filament/Books/Pages'), for: 'App\Filament\Books\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Books/Widgets'), for: 'App\Filament\Books\Widgets')
            ->navigationGroups([
                'Boekhouding',
                'Verkoop',
                'Relaties',
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
