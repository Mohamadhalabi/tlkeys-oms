<?php
// app/Providers/Filament/SellerPanelProvider.php
namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Filament\Pages;
use Filament\Navigation\MenuItem; // <-- add this
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Http\Middleware\SetLocaleFromSession; // <-- add this

class SellerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('seller')
            ->path('seller')
            ->brandName('TLKeys OMS — Seller')
            ->login()
            ->authGuard('web')

            // 👇 add the same locale middleware used by Admin
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                SetLocaleFromSession::class,   // <—
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class])

            // Locale switch entries in the user menu
            ->userMenuItems([
                MenuItem::make()->label('English')->url(fn () => route('seller.set-locale', ['locale' => 'en'])),
                MenuItem::make()->label('العربية')->url(fn () => route('seller.set-locale', ['locale' => 'ar'])),
            ])

            ->resources([
                \App\Filament\Resources\OrderResource::class,
                \App\Filament\Resources\CustomerResource::class,
                \App\Filament\Resources\ProductResource::class,
            ])
            ->pages([Pages\Dashboard::class])
            ->navigationGroups(['Sales', 'Customers', 'Catalog']);
    }
}
