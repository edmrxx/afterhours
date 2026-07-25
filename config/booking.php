<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hold window
    |--------------------------------------------------------------------------
    |
    | Minutes a freshly reserved booking keeps its slot off the market before
    | ReleaseExpiredBookingHolds frees it again. Once the customer submits a
    | payment reference the hold is extended to `verification_hold_minutes` so a
    | human has time to check the transaction against the merchant account.
    |
    */

    'hold_minutes' => (int) env('BOOKING_HOLD_MINUTES', 30),

    'verification_hold_minutes' => (int) env('BOOKING_VERIFICATION_HOLD_MINUTES', 720),

    /*
    |--------------------------------------------------------------------------
    | Public booking code
    |--------------------------------------------------------------------------
    |
    | Prefix for the customer-facing identifier, e.g. PHEA-K7M4XQ9B.
    |
    */

    'code_prefix' => env('BOOKING_CODE_PREFIX', 'PHEA'),

    /*
    |--------------------------------------------------------------------------
    | Court pricing
    |--------------------------------------------------------------------------
    |
    | Pricing is a single, club-wide (global) rate table — every court charges
    | the same, so it lives here and in Admin > Settings > Booking rather than on
    | the court. There are exactly two tiers: non-peak and peak. Each tier owns a
    | rate and a clock window, and a window may legitimately cross midnight — the
    | default peak run of 4:00 PM to 2:00 AM is the whole reason PricingService
    | resolves the tier with wrap-around rather than a plain start < end compare.
    |
    | A saved row in the `system` settings group overrides any of these without
    | touching `.env`; these values are only the shipped default for a fresh
    | install (see App\Services\PricingService).
    |
    */

    'pricing' => [
        'non_peak_rate' => (float) env('BOOKING_NON_PEAK_RATE', 450),
        'peak_rate' => (float) env('BOOKING_PEAK_RATE', 500),

        // The peak window is the only clock setting: a slot inside it is peak,
        // every other hour is non-peak. 24-hour "HH:MM"; the default 4pm–2am
        // wraps past midnight, which PricingService resolves.
        'peak_start' => env('BOOKING_PEAK_START', '16:00'),
        'peak_end' => env('BOOKING_PEAK_END', '02:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operations mailbox
    |--------------------------------------------------------------------------
    |
    | Receives the new-booking alert that prompts manual payment verification.
    |
    */

    'admin_email' => env('BOOKING_ADMIN_EMAIL', 'admin@phea.test'),

    /*
    |--------------------------------------------------------------------------
    | Notification queue connection
    |--------------------------------------------------------------------------
    |
    | The booking notifications implement ShouldQueue and honour this value from
    | viaConnection(). It defaults to `sync` so a fresh install delivers mail and
    | SMS inline without anyone having to run `queue:work` — otherwise every
    | confirmation silently piles up in the jobs table.
    |
    | Once a worker is running in production, set BOOKING_NOTIFICATION_CONNECTION
    | to `database` so the public reserve/payment requests stop paying the SMTP
    | and Semaphore round trip on the customer's clock.
    |
    */

    'notification_connection' => env('BOOKING_NOTIFICATION_CONNECTION', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    'per_page' => (int) env('BOOKING_PER_PAGE', 25),

    /*
    |--------------------------------------------------------------------------
    | Bulk slot generator ceiling
    |--------------------------------------------------------------------------
    |
    | Hard safety limit on how many slots a single generator run may create.
    | A wide date range crossed with a long opening window is trivially able to
    | produce six figures of rows; refuse rather than lock the table.
    |
    */

    'max_generate_slots' => (int) env('BOOKING_MAX_GENERATE_SLOTS', 2000),

];
