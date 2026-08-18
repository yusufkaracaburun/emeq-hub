<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\ConnectionStatsWidget;
use App\Filament\Widgets\OperationalHealthWidget;
use App\Filament\Widgets\PlatformScaleWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
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
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->authGuard('web')
            ->brandName('Emeq Hub')
            ->favicon(asset('favicon.ico'))
            ->colors([
                'primary' => Color::Amber,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverResources(in: app_path('Filament/Books/Resources'), for: 'App\Filament\Books\Resources')
            ->discoverPages(in: app_path('Filament/Books/Pages'), for: 'App\Filament\Books\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('Koppelingen')
                    ->icon('heroicon-o-link')
                    ->extraSidebarAttributes([
                        'title' => 'SaaS-apps (Consumer) → Accounts → partner-Connections + audit',
                    ]),
                NavigationGroup::make('Abonnementen')
                    ->icon('heroicon-o-credit-card'),
                NavigationGroup::make('Boekhouding')
                    ->icon('heroicon-o-calculator')
                    ->collapsed(),
                NavigationGroup::make('Beheer')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsed(),
            ])
            ->navigationItems([
                NavigationItem::make('Logs')
                    ->url('/log-viewer')
                    ->icon('heroicon-o-document-text')
                    ->group('Beheer')
                    ->sort(90)
                    ->openUrlInNewTab()
                    ->visible(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false),
                NavigationItem::make('Horizon')
                    ->url('/horizon')
                    ->icon('heroicon-o-queue-list')
                    ->group('Beheer')
                    ->sort(91)
                    ->openUrlInNewTab()
                    ->visible(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                PlatformScaleWidget::class,
                OperationalHealthWidget::class,
                ConnectionStatsWidget::class,
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
