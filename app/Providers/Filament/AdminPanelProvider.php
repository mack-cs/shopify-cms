<?php

namespace App\Providers\Filament;

use App\Http\Controllers\DeleteApprovalAlertController;
use App\Http\Controllers\PartialApprovalAlertController;
use App\Http\Middleware\AuthenticatePanelUser;
use App\Http\Controllers\Auth\RedirectToLoginController;
use Filament\Http\Controllers\Auth\LogoutController;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Assets\Css;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\RequireTwoFactorAuthentication;
use App\Filament\Widgets\SeoMetricsStats;
use App\Filament\Widgets\SeoPeriodComparisonWidget;
use App\Filament\Widgets\SeoMetricsTrendChart;
use App\Filament\Widgets\SeoTopEntitiesStackedChart;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login(RedirectToLoginController::class)
            ->loginRouteSlug('panel-login')
            ->authenticatedRoutes(function (): void {
                Route::name('auth.')->group(function (): void {
                    Route::post('/panel-logout', LogoutController::class)->name('logout');
                });

                Route::get('/delete-approval-alerts', DeleteApprovalAlertController::class)
                    ->name('delete-approval-alerts');
                Route::post('/delete-approval-alerts/{deletionRequest}/approve', [DeleteApprovalAlertController::class, 'approve'])
                    ->name('delete-approval-alerts.approve');
                Route::post('/delete-approval-alerts/{deletionRequest}/reject', [DeleteApprovalAlertController::class, 'reject'])
                    ->name('delete-approval-alerts.reject');
                Route::get('/partial-approval-alerts', PartialApprovalAlertController::class)
                    ->name('partial-approval-alerts');
            })
            ->colors([
                'primary' => Color::Amber,
            ])
            ->favicon(asset('favicon.png'))
            ->assets([
                Css::make('filament-admin', asset('css/filament-admin.css')),
            ])
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): string => view('filament.partials.scroll-to-top-listener')->render(),
            )
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): string => view('filament.partials.delete-approval-alert')->render(),
            )
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): string => view('filament.partials.partial-approval-alert')->render(),
            )
            ->databaseNotifications()
            ->databaseNotificationsPolling('10s')
            ->unsavedChangesAlerts()
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Product Data'),
                NavigationGroup::make('Catalog'),
                NavigationGroup::make('Configurations'),
                NavigationGroup::make('Content'),
                NavigationGroup::make('SEO'),
                NavigationGroup::make('Audit & History'),
                NavigationGroup::make('User Management'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                SeoMetricsStats::class,
                SeoMetricsTrendChart::class,
                SeoTopEntitiesStackedChart::class,
                SeoPeriodComparisonWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                AuthenticatePanelUser::class,
                ForcePasswordChange::class,
                RequireTwoFactorAuthentication::class,
            ]);
    }
}
