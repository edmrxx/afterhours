<?php

use App\Http\Controllers\Admin\AuditTrailController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\CourtController;
use App\Http\Controllers\Admin\CourtSlotController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\Settings\CompanySettingsController;
use App\Http\Controllers\Admin\Settings\PaymentSettingsController;
use App\Http\Controllers\Admin\Settings\SystemSettingsController;
use App\Http\Controllers\Admin\Settings\ThemeSettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicSite\PublicBookingController;
use App\Http\Controllers\PublicSite\PublicCourtController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public booking flow — no authentication
|--------------------------------------------------------------------------
|
| A visitor browses courts, picks an open slot, enters their details (which
| immediately places a hold so nobody can pay for the same slot twice), pays
| via GCash off-platform, then submits the reference number for the admin to
| verify manually.
|
*/

Route::get('/', [PublicCourtController::class, 'index'])->name('public.courts.index');
Route::get('/courts/{court:slug}', [PublicCourtController::class, 'show'])->name('public.courts.show');

Route::prefix('booking')->name('public.booking.')->group(function () {
    // Step 1 — claim the slot. Creates the booking in `awaiting_payment` and
    // starts the hold clock. Throttled: holds are a denial-of-service vector.
    Route::post('/reserve', [PublicBookingController::class, 'reserve'])
        ->middleware('throttle:10,1')
        ->name('reserve');

    // Step 2 — show GCash QR + amount, then accept the reference number.
    Route::get('/{booking:code}/payment', [PublicBookingController::class, 'payment'])->name('payment');
    Route::post('/{booking:code}/payment', [PublicBookingController::class, 'submitPayment'])
        ->middleware('throttle:10,1')
        ->name('payment.store');

    // Step 3 — shareable status page the customer can revisit any time.
    Route::get('/{booking:code}', [PublicBookingController::class, 'status'])->name('status');
    Route::post('/{booking:code}/cancel', [PublicBookingController::class, 'cancel'])->name('cancel');
});

// "Check my booking" — code lookup form on the public site.
Route::get('/lookup', [PublicBookingController::class, 'lookupForm'])->name('public.booking.lookup');
Route::post('/lookup', [PublicBookingController::class, 'lookup'])
    ->middleware('throttle:20,1')
    ->name('public.booking.lookup.submit');

/*
|--------------------------------------------------------------------------
| Authentication — username based
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Reachable even while `must_change_password` is set — it is the way out.
    Route::get('/password/change', [PasswordController::class, 'edit'])->name('password.change');
    Route::put('/password/change', [PasswordController::class, 'update'])->name('password.update');
});

/*
|--------------------------------------------------------------------------
| Admin area
|--------------------------------------------------------------------------
|
| Every route is permission-gated. The sidebar reads the same permission
| names from the shared Inertia props, so menu visibility and route
| protection can never drift apart.
|
*/

Route::middleware(['auth', 'active', 'password.changed'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', [DashboardController::class, 'index'])
            ->middleware('permission:dashboard.view')
            ->name('dashboard');

        Route::get('/dashboard/chart', [DashboardController::class, 'chart'])
            ->middleware('permission:dashboard.view')
            ->name('dashboard.chart');

        /* ---------------------------------------------------------------- */
        /* Courts                                                            */
        /* ---------------------------------------------------------------- */
        Route::controller(CourtController::class)->prefix('courts')->name('courts.')->group(function () {
            Route::get('/', 'index')->middleware('permission:courts.view')->name('index');
            Route::get('/create', 'create')->middleware('permission:courts.create')->name('create');
            Route::post('/', 'store')->middleware('permission:courts.create')->name('store');
            Route::get('/{court}', 'show')->middleware('permission:courts.view')->name('show');
            Route::get('/{court}/edit', 'edit')->middleware('permission:courts.update')->name('edit');
            Route::put('/{court}', 'update')->middleware('permission:courts.update')->name('update');
            Route::patch('/{court}/status', 'toggleStatus')->middleware('permission:courts.update')->name('status');
            Route::delete('/{court}', 'destroy')->middleware('permission:courts.delete')->name('destroy');
        });

        /* ---------------------------------------------------------------- */
        /* Court slots — flexible, admin defined                             */
        /* ---------------------------------------------------------------- */
        Route::controller(CourtSlotController::class)->prefix('slots')->name('slots.')->group(function () {
            Route::get('/', 'index')->middleware('permission:slots.view')->name('index');
            Route::post('/', 'store')->middleware('permission:slots.create')->name('store');
            Route::put('/{slot}', 'update')->middleware('permission:slots.update')->name('update');
            Route::patch('/{slot}/block', 'toggleBlock')->middleware('permission:slots.update')->name('block');
            Route::delete('/{slot}', 'destroy')->middleware('permission:slots.delete')->name('destroy');

            // Bulk generator: date range x days-of-week x opening..closing.
            Route::post('/generate', 'generate')->middleware('permission:slots.generate')->name('generate');
            Route::post('/generate/preview', 'preview')->middleware('permission:slots.generate')->name('generate.preview');
            Route::delete('/bulk', 'bulkDestroy')->middleware('permission:slots.delete')->name('bulk.destroy');

            // On-demand reprice: push a court's current rates onto its already
            // generated `available` slots. Same gate as the generator itself —
            // both are bulk pricing actions on the same schedule.
            Route::post('/reprice', 'reprice')->middleware('permission:slots.generate')->name('reprice');

            // On-demand sync: reshape every court's future schedule to the club's
            // operating hours (Settings > Company) — add the newly-open hours,
            // drop the now-closed available ones. It both generates AND deletes, so
            // it requires BOTH permissions (two middleware = AND, not the | of one).
            Route::post('/sync-hours', 'syncOperatingHours')
                ->middleware(['permission:slots.generate', 'permission:slots.delete'])
                ->name('sync-hours');
        });

        /* ---------------------------------------------------------------- */
        /* Bookings — manual GCash verification                              */
        /* ---------------------------------------------------------------- */
        Route::controller(BookingController::class)->prefix('bookings')->name('bookings.')->group(function () {
            Route::get('/', 'index')->middleware('permission:bookings.view')->name('index');
            Route::get('/{booking}', 'show')->middleware('permission:bookings.view')->name('show');
            Route::post('/{booking}/confirm', 'confirm')->middleware('permission:bookings.verify')->name('confirm');
            Route::post('/{booking}/reject', 'reject')->middleware('permission:bookings.verify')->name('reject');
            Route::post('/{booking}/complete', 'complete')->middleware('permission:bookings.verify')->name('complete');
            Route::delete('/{booking}', 'destroy')->middleware('permission:bookings.delete')->name('destroy');
        });

        /* ---------------------------------------------------------------- */
        /* Users & roles                                                     */
        /* ---------------------------------------------------------------- */
        Route::controller(UserController::class)->prefix('users')->name('users.')->group(function () {
            Route::get('/', 'index')->middleware('permission:users.view')->name('index');
            Route::get('/create', 'create')->middleware('permission:users.create')->name('create');
            Route::post('/', 'store')->middleware('permission:users.create')->name('store');
            Route::get('/{user}/edit', 'edit')->middleware('permission:users.update')->name('edit');
            Route::put('/{user}', 'update')->middleware('permission:users.update')->name('update');
            Route::patch('/{user}/status', 'toggleStatus')->middleware('permission:users.update')->name('status');
            Route::post('/{user}/reset-password', 'resetPassword')->middleware('permission:users.update')->name('reset');
            Route::delete('/{user}', 'destroy')->middleware('permission:users.delete')->name('destroy');
        });

        Route::controller(RoleController::class)->prefix('roles')->name('roles.')->group(function () {
            Route::get('/', 'index')->middleware('permission:roles.view')->name('index');
            Route::get('/create', 'create')->middleware('permission:roles.create')->name('create');
            Route::post('/', 'store')->middleware('permission:roles.create')->name('store');
            Route::get('/{role}/edit', 'edit')->middleware('permission:roles.update')->name('edit');
            Route::put('/{role}', 'update')->middleware('permission:roles.update')->name('update');
            Route::delete('/{role}', 'destroy')->middleware('permission:roles.delete')->name('destroy');
        });

        /* ---------------------------------------------------------------- */
        /* Settings                                                          */
        /* ---------------------------------------------------------------- */
        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/payment', [PaymentSettingsController::class, 'edit'])
                ->middleware('permission:settings.view')->name('payment');
            Route::put('/payment', [PaymentSettingsController::class, 'update'])
                ->middleware('permission:settings.update')->name('payment.update');

            Route::get('/company', [CompanySettingsController::class, 'edit'])
                ->middleware('permission:settings.view')->name('company');
            Route::put('/company', [CompanySettingsController::class, 'update'])
                ->middleware('permission:settings.update')->name('company.update');

            Route::get('/theme', [ThemeSettingsController::class, 'edit'])
                ->middleware('permission:settings.view')->name('theme');
            Route::put('/theme', [ThemeSettingsController::class, 'update'])
                ->middleware('permission:settings.update')->name('theme.update');

            Route::get('/system', [SystemSettingsController::class, 'edit'])
                ->middleware('permission:settings.view')->name('system');
            Route::put('/system', [SystemSettingsController::class, 'update'])
                ->middleware('permission:settings.update')->name('system.update');
        });

        /* ---------------------------------------------------------------- */
        /* Audit trail & reports                                             */
        /* ---------------------------------------------------------------- */
        Route::get('/audit-trail', [AuditTrailController::class, 'index'])
            ->middleware('permission:audit.view')->name('audit.index');
        Route::get('/audit-trail/{auditTrail}', [AuditTrailController::class, 'show'])
            ->middleware('permission:audit.view')->name('audit.show');

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/bookings', [ReportController::class, 'bookings'])
                ->middleware('permission:reports.view')->name('bookings');
            Route::get('/revenue', [ReportController::class, 'revenue'])
                ->middleware('permission:reports.view')->name('revenue');
            Route::get('/export/{type}', [ReportController::class, 'export'])
                ->middleware('permission:reports.export')->name('export');
        });

        /* ---------------------------------------------------------------- */
        /* Own profile — no permission needed, everyone manages themselves    */
        /* ---------------------------------------------------------------- */
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    });
