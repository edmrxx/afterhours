<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Guarantees every settings key the UI reads actually exists, so a Settings
 * screen on a fresh install renders empty fields instead of blowing up on a
 * missing row. Existing values are never overwritten.
 */
class PaymentSettingsSeeder extends Seeder
{
    /**
     * group => [key => [type, default]]
     *
     * @var array<string, array<string, array{0: string, 1: string|null}>>
     */
    private const DEFAULTS = [
        Setting::GROUP_PAYMENT => [
            // Uploaded through the payment settings screen; empty until then.
            'gcash_qr_path' => ['image', null],
            'gcash_account_name' => ['string', ''],
            'gcash_account_number' => ['string', ''],

            // GoTyme is the optional second method. Seeded blank on purpose:
            // the keys must exist so the settings screen renders empty fields,
            // but an unconfigured GoTyme is a valid — and the default — state.
            'gotyme_qr_path' => ['image', null],
            'gotyme_account_name' => ['string', ''],
            'gotyme_account_number' => ['string', ''],

            // One instruction block covers both methods, so the wording stays
            // method-neutral — naming GCash here would be wrong the moment a
            // customer pays through the GoTyme QR.
            'payment_instructions' => ['text', "1. Scan the QR code for your payment app, or send to the account number shown above.\n2. Pay the exact booking amount.\n3. Wait for the confirmation message — verification is manual and usually takes under an hour."],
        ],

        Setting::GROUP_COMPANY => [
            'company_name' => ['string', 'The Paddle Room'],
            'company_tagline' => ['string', 'Pickleball, properly scheduled.'],
            // Deliberately blank: a real contact address belongs to the client
            // and is set in Admin > Settings > Company, not invented here.
            'company_email' => ['string', ''],
            'company_phone' => ['string', ''],
            'company_address' => ['text', ''],
            'company_logo_path' => ['image', null],
            'company_facebook' => ['string', ''],

            // Operating hours are now structured (24-hour "HH:MM"), and the peak
            // window aside, this is the club's own open/close clock. The window
            // may cross midnight (07:00 → 02:00), which the label formatter and
            // the slot generator both resolve. `operating_hours` below is the
            // human label DERIVED from these on save — kept as its own row so
            // existing readers (the header preview) need no change.
            'operating_opening_time' => ['string', '07:00'],
            'operating_closing_time' => ['string', '02:00'],
            'operating_hours' => ['string', '7:00 AM – 2:00 AM daily'],
        ],

        Setting::GROUP_THEME => [
            // Stored as a token name, not a hex value — components resolve it
            // through the Tailwind theme so the palette stays single-sourced.
            'primary_palette' => ['string', 'brand'],
            'sidebar_style' => ['string', 'light'],
            'hero_headline' => ['string', 'Your court. Your hour.'],
            'hero_subheadline' => ['text', 'Book a court in a few taps. Every time slot is checked against live availability for the exact court and date you choose.'],
            'hero_image_path' => ['image', null],
        ],

        Setting::GROUP_SYSTEM => [
            'booking_terms' => ['text', 'Slots are held only until the payment window closes. Unpaid holds are released automatically.'],
            'cancellation_policy' => ['text', 'Bookings may be cancelled by the customer any time before payment is verified.'],

            // The owner's own inbox, kept separate from the public company_email.
            // When set, it receives a plain heads-up the moment a booking is placed
            // or confirmed — a "someone booked" reminder, not a work item like the
            // verifier alert (see BookingService::notifyOwner()). Blank disables it.
            'owner_notification_email' => ['string', ''],

            'booking_hold_minutes' => ['integer', '30'],
            'booking_verification_hold_minutes' => ['integer', '720'],
            // Overrides config('booking.code_prefix') / BOOKING_CODE_PREFIX without
            // touching .env — see Booking::codePrefix().
            'booking_code_prefix' => ['string', 'PHEA'],

            // Global court pricing — a rate per tier plus the peak clock window
            // (non-peak is every hour outside it). The peak window deliberately
            // crosses midnight (4pm–2am). Overrides config('booking.pricing.*')
            // without touching .env — read through App\Services\PricingService.
            'pricing_non_peak_rate' => ['string', '450'],
            'pricing_peak_rate' => ['string', '500'],
            'pricing_peak_start' => ['string', '16:00'],
            'pricing_peak_end' => ['string', '02:00'],
        ],
    ];

    public function run(): void
    {
        $created = 0;

        foreach (self::DEFAULTS as $group => $keys) {
            foreach ($keys as $key => [$type, $default]) {
                $setting = Setting::firstOrCreate(
                    ['group' => $group, 'key' => $key],
                    ['type' => $type, 'value' => $default],
                );

                if ($setting->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        $this->command?->info("Settings seeded: {$created} new key(s), existing values preserved.");
    }
}
