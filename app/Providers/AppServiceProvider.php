<?php

declare(strict_types=1);

namespace App\Providers;

use App\Notifications\Channels\SmsChannel;
use App\Services\AuditTrailService;
use App\Services\BookingService;
use App\Services\PaymentMethodService;
use App\Services\SettingsService;
use App\Services\SmsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Policies that Laravel's convention-based discovery cannot find, or that
     * we want bound explicitly. Guarded by class_exists so the container never
     * blows up on a module that has not landed yet.
     *
     * @var array<class-string, class-string>
     */
    private const POLICIES = [
        \App\Models\Booking::class => \App\Policies\BookingPolicy::class,
        \App\Models\Court::class => \App\Policies\CourtPolicy::class,
        \App\Models\CourtSlot::class => \App\Policies\CourtSlotPolicy::class,
        \App\Models\User::class => \App\Policies\UserPolicy::class,
        \App\Models\AuditTrail::class => \App\Policies\AuditTrailPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Stateless, cache-backed helpers: one instance per request is both
        // cheaper and, for SettingsService, correct — its in-request memo only
        // pays off when the same instance is reused.
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(AuditTrailService::class);
        $this->app->singleton(SmsService::class);
        $this->app->singleton(BookingService::class);
        // Reads nothing but SettingsService, and both the checkout render and
        // the payment POST resolve it in the same request.
        $this->app->singleton(PaymentMethodService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureModels();
        $this->configurePagination();
        $this->configureNotifications();
        $this->configurePolicies();
        $this->configureUrls();
    }

    /**
     * Development-time strictness.
     *
     * `preventLazyLoading` turns every N+1 into a loud exception, which is
     * exactly what we want while building — and exactly what we do not want in
     * production, where a missed `with()` should degrade to a slow page, not a
     * 500 in front of a paying customer.
     */
    private function configureModels(): void
    {
        Model::preventLazyLoading($this->app->isLocal());
    }

    private function configurePagination(): void
    {
        // Tailwind-flavoured pagination views. Inertia pages render `links`
        // themselves, but reports and any server-rendered list inherit these.
        Paginator::defaultView('pagination::tailwind');
        Paginator::defaultSimpleView('pagination::simple-tailwind');
    }

    /**
     * Wire the custom `sms` notification channel used by the booking
     * notifications, so `Notification::route('sms', $phone)` works anywhere.
     */
    private function configureNotifications(): void
    {
        Notification::extend('sms', fn ($app): SmsChannel => new SmsChannel($app->make(SmsService::class)));
    }

    private function configurePolicies(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            if (class_exists($model) && class_exists($policy)) {
                Gate::policy($model, $policy);
            }
        }
    }

    /**
     * Honour the scheme configured in APP_URL so signed URLs and mailed links
     * are not generated as http:// behind a TLS-terminating proxy.
     */
    private function configureUrls(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
