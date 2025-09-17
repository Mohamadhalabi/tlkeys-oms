<?php

namespace App\Providers\Filament;

use Filament\Navigation\NavigationItem;
use Filament\Navigation\MenuItem;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Http\Middleware\SetLocaleFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors(['primary' => Color::Amber])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Pages\Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([Widgets\AccountWidget::class, Widgets\FilamentInfoWidget::class])

            // 🔹 Custom navigation (translated)
            ->navigationItems([
                NavigationItem::make('customers-nav') // any key; the label is set below
                    ->label(fn () => __('Customers'))
                    ->group(fn () => __('Customers'))
                    ->icon('heroicon-o-users')
                    ->url(fn () => url('/admin/customers'))
                    ->isActiveWhen(fn () => request()->is('admin/customers*')),

                NavigationItem::make('import-customers-nav')
                    ->label(fn () => __('Import customers'))
                    ->group(fn () => __('Customers'))
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->visible(fn () => auth()->user()?->hasRole('admin'))
                    ->url(fn () => url('/admin/import-customers'))
                    ->isActiveWhen(fn () => request()->is('admin/import-customers')),
            ])



            // quick locale switchers
            ->userMenuItems([
                MenuItem::make()->label('English')->url(fn () => route('admin.set-locale', ['locale' => 'en'])),
                MenuItem::make()->label('العربية')->url(fn () => route('admin.set-locale', ['locale' => 'ar'])),
            ])

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                SetLocaleFromSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);

        // (optional) load translatable plugin if present
        if (class_exists(\Filament\SpatieLaravelTranslatablePlugin\SpatieLaravelTranslatablePlugin::class)) {
            $panel->plugins([
                \Filament\SpatieLaravelTranslatablePlugin\SpatieLaravelTranslatablePlugin::make()
                    ->defaultLocales(['en','ar']),
            ]);
        }

        return $panel;
    }
}
