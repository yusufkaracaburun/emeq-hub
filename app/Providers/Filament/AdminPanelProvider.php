<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\ConnectionStatsWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // IN-03: ->default() markeert dit paneel als Filament's default-panel. Side-effect:
        // Filament::auth() (zonder panel-id) pakt deze guard. Voor toekomstige consumer-portal-
        // panels (v1.0+) moet ->default() expliciet naar het nieuwe paneel verhuizen.
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->authGuard('web')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // Plan 08-04 (D-07): canonical Tenants-tooltip via extraSidebarAttributes — pinned
            // op de NavigationGroup-API door `vendor/filament/filament/src/Navigation/NavigationGroup.php`
            // (geen native ->description()-slot in v4.11). De andere 3 navgroups MOETEN expliciet
            // worden gedeclareerd zodat Filament hun rendering-volgorde respecteert.
            ->navigationGroups([
                NavigationGroup::make('Tenants')
                    ->extraSidebarAttributes([
                        'title' => 'SaaS-apps (Consumer) → hun klanten (Account) → partner-koppelingen (Connection)',
                    ]),
                NavigationGroup::make('Integraties'),
                NavigationGroup::make('Abonnementen'),
                NavigationGroup::make('Beheer'),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                ConnectionStatsWidget::class,
                FilamentInfoWidget::class,
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

        if (! app()->isProduction()) {
            $panel->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): string => Blade::render(<<<'BLADE'
                    <div class="fi-section-content mt-4 border-t border-gray-200 pt-4 dark:border-white/10">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Dev shortcuts (niet beschikbaar in productie):</p>
                        <div class="flex gap-2">
                            <a href="/admin/quick-login/super-admin"
                               class="fi-btn fi-btn-color-primary fi-btn-size-sm inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold bg-amber-500 text-white hover:bg-amber-600">
                                Snel inloggen — super-admin
                            </a>
                            <a href="/admin/quick-login/staff"
                               class="fi-btn fi-btn-color-gray fi-btn-size-sm inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold bg-gray-200 text-gray-900 hover:bg-gray-300 dark:bg-gray-700 dark:text-white">
                                Snel inloggen — staff
                            </a>
                        </div>
                    </div>
                BLADE)
            );
        }

        return $panel;
    }
}
