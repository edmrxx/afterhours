<?php

use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Versioned from day one so a future mobile app can pin /api/v1.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'password.changed' => \App\Http\Middleware\EnsurePasswordIsChanged::class,
        ]);

        // There is no route named `dashboard` or `home` in this app — without
        // this, an already-authenticated visitor hitting a guest-only route
        // (e.g. clicking "Staff" on the public site while still logged in)
        // falls through the framework's default lookup all the way to `/`,
        // silently bouncing them back to the page they were already on.
        RedirectIfAuthenticated::redirectUsing(fn () => route('admin.dashboard'));
    })
    ->withSchedule(function (Schedule $schedule) {
        /*
         * Return lapsed payment holds to the market.
         *
         * Every minute, because a 30-minute hold that lingers for five extra
         * minutes is a slot nobody can buy. `withoutOverlapping()` guards the
         * case where a large backlog makes one pass run past the next tick —
         * two concurrent passes would still be safe (every release re-checks
         * under a row lock) but pointless, and they would double the load.
         *
         * `runInBackground()` keeps this off the critical path of anything
         * else scheduled on the same minute.
         */
        $schedule->command('bookings:release-holds')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->runInBackground();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

// Split-folder shared hosting: the web root is a sibling `public_html`
// rather than `phea_app/public`. Point the public path there so server-side
// public_path() lookups — notably Vite's `build/manifest.json` — resolve to
// the folder the assets were actually uploaded to. Guarded on the manifest's
// presence so local development (which has no such sibling) is untouched.
$siblingPublicHtml = dirname($app->basePath()).'/public_html';

if (is_file($siblingPublicHtml.'/build/manifest.json')) {
    $app->usePublicPath($siblingPublicHtml);
}

return $app;
