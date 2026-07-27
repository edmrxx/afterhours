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
    | Prefix for the customer-facing identifier, e.g. AH-K7M4XQ9B.
    |
    */

    'code_prefix' => env('BOOKING_CODE_PREFIX', 'AH'),

    /*
    |--------------------------------------------------------------------------
    | Court pricing
    |--------------------------------------------------------------------------
    |
    | Rates are a grid: one row per court CATEGORY (see App\Models\Court::
    | CATEGORIES), one column per time tier. A full-size court and the Skinny
    | Court charge very different money, so the category decides the row; the
    | shared peak window decides the column.
    |
    | The peak window is the only clock setting — a slot starting inside it is
    | peak, every other hour is non-peak — and it may legitimately cross
    | midnight, which is why PricingService resolves the tier with wrap-around
    | rather than a plain start < end compare. The shipped default runs 5:00 PM
    | to midnight, matching the club's published rate card; a club that trades
    | past midnight simply moves `peak_end` later (e.g. 02:00) and the wrap
    | handling covers it with no code change.
    |
    | A saved row in the `system` settings group overrides any of these without
    | touching `.env`; these values are only the shipped default for a fresh
    | install (see App\Services\PricingService).
    |
    */

    'pricing' => [
        // Keyed by Court::CATEGORIES. A category with no entry here prices at
        // zero rather than throwing, so a half-finished config is visible on
        // the settings screen instead of fatal on the booking page.
        'categories' => [
            'normal' => [
                'non_peak_rate' => (float) env('BOOKING_NORMAL_NON_PEAK_RATE', 550),
                'peak_rate' => (float) env('BOOKING_NORMAL_PEAK_RATE', 650),
            ],
            'skinny' => [
                'non_peak_rate' => (float) env('BOOKING_SKINNY_NON_PEAK_RATE', 200),
                'peak_rate' => (float) env('BOOKING_SKINNY_PEAK_RATE', 300),
            ],
        ],

        // 24-hour "HH:MM". 17:00 → 00:00 is the published 5PM–12MN evening band.
        'peak_start' => env('BOOKING_PEAK_START', '17:00'),
        'peak_end' => env('BOOKING_PEAK_END', '00:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operations mailbox
    |--------------------------------------------------------------------------
    |
    | Receives the new-booking alert that prompts manual payment verification.
    |
    */

    'admin_email' => env('BOOKING_ADMIN_EMAIL', 'admin@afterhours.test'),

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
