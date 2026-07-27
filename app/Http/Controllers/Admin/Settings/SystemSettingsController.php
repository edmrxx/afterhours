<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSystemSettingsRequest;
use App\Models\Court;
use App\Models\Setting;
use App\Services\AuditTrailService;
use App\Services\PricingService;
use App\Services\SettingsService;
use App\Services\SlotGeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Booking hold windows — how long an unpaid reservation keeps its slot before
 * `bookings:release-holds` (see App\Console\Commands\ReleaseExpiredBookingHolds)
 * releases it back to the market — plus the prefix new booking codes are
 * generated with (see Booking::codePrefix()) and the court pricing grid
 * (see App\Services\PricingService). `config/booking.php` remains the shipped
 * default for a fresh install; a saved row here overrides it without touching
 * `.env`.
 *
 * The pricing grid is one rate per (court category, tier) pair plus the single
 * peak window they all share. Every key is derived from PricingService rather
 * than listed here, so adding a category to Court::CATEGORIES cannot leave this
 * screen silently writing a stale set of keys.
 */
class SystemSettingsController extends Controller
{
    /** The non-pricing keys, which have fixed names and types. */
    private const BASE_TYPES = [
        'booking_hold_minutes' => 'integer',
        'booking_verification_hold_minutes' => 'integer',
        'booking_code_prefix' => 'string',
        'owner_notification_email' => 'string',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PricingService $pricing,
        private readonly AuditTrailService $audit,
        private readonly SlotGeneratorService $generator,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/System', [
            'settings' => $this->stored(),
            // The grid's shape, so the form can render one labelled row per
            // category without hard-coding a category list in the component.
            'pricingCategories' => $this->pricingCategories(),
        ]);
    }

    public function update(UpdateSystemSettingsRequest $request): RedirectResponse
    {
        $before = $this->stored();

        $after = [
            'booking_hold_minutes' => (int) $request->input('booking_hold_minutes'),
            'booking_verification_hold_minutes' => (int) $request->input('booking_verification_hold_minutes'),
            'booking_code_prefix' => mb_strtoupper(trim((string) $request->input('booking_code_prefix'))),
            // Blank is a valid, meaningful value here — it switches the owner
            // heads-up off — so it is stored as an empty string, not skipped.
            'owner_notification_email' => trim((string) $request->input('owner_notification_email', '')),
        ];

        // Money is normalised to two decimals so the stored string matches what
        // the rest of the app rounds to; times are already "HH:MM".
        foreach ($this->rateKeys() as $key) {
            $after[$key] = number_format((float) $request->input($key), 2, '.', '');
        }

        $after['pricing_peak_start'] = (string) $request->input('pricing_peak_start');
        $after['pricing_peak_end'] = (string) $request->input('pricing_peak_end');

        $pricingChanged = $this->pricingChanged($before, $after);

        // A change to the rate grid is meant to apply everywhere at once: the
        // moment any rate or the peak window moves, every already-generated
        // `available` slot on every court is repriced to match, in this same
        // request — each court at its own category's rate, and held or booked
        // slots keeping the price the customer agreed to (see
        // SlotGeneratorService::reprice()). A save that left the pricing
        // untouched reprices nothing.
        //
        // The write and the reprice it triggers ride in ONE transaction on
        // purpose. If the bulk reprice fails partway (a deadlock on one court, say)
        // the rate change rolls back with it, rather than persisting a rate that
        // only some courts were repriced to — which the pricingChanged() gate would
        // then read as "nothing to do" on the next save, silently stranding the
        // rest. Rolling both back together makes simply saving again a true retry;
        // `attempts: 3` first rides out a transient deadlock without troubling the
        // admin. The reprice reads the new rates because setMany() busts the
        // settings cache before repriceAll() runs.
        $repriced = null;

        DB::transaction(function () use ($after, $pricingChanged, &$repriced): void {
            $this->settings->setMany(Setting::GROUP_SYSTEM, $after, $this->types());

            $repriced = $pricingChanged ? $this->generator->repriceAll() : null;
        }, 3);

        // Audit only what actually committed.
        $this->recordAudit($before, $after);
        $this->recordRepriceAudit($repriced);

        return to_route('admin.settings.system')
            ->with('success', $this->savedMessage($repriced));
    }

    /* --------------------------------------------------------------------- */
    /* Pricing keys                                                           */
    /* --------------------------------------------------------------------- */

    /**
     * Every rate key in the grid — category × tier, no window boundaries.
     *
     * @return list<string>
     */
    private function rateKeys(): array
    {
        $keys = [];

        foreach (Court::categoryKeys() as $category) {
            foreach (PricingService::TIERS as $tier) {
                $keys[] = PricingService::rateKey($category, $tier);
            }
        }

        return $keys;
    }

    /**
     * The grid's shape for the form: one entry per category, naming the two
     * fields that category's row renders.
     *
     * @return list<array{key: string, label: string, non_peak_field: string, peak_field: string}>
     */
    private function pricingCategories(): array
    {
        $rows = [];

        foreach (Court::CATEGORIES as $key => $label) {
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'non_peak_field' => PricingService::rateKey($key, PricingService::TIER_NON_PEAK),
                'peak_field' => PricingService::rateKey($key, PricingService::TIER_PEAK),
            ];
        }

        return $rows;
    }

    /**
     * Settings key => storage type. Money and clock strings alike ride in the
     * text column as strings.
     *
     * @return array<string, string>
     */
    private function types(): array
    {
        $types = self::BASE_TYPES;

        foreach (PricingService::settingKeys() as $key) {
            $types[$key] = 'string';
        }

        return $types;
    }

    /* --------------------------------------------------------------------- */
    /* Internals                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * The saved (or shipped-default) values, in the exact string/int shape
     * `update()` writes — so a re-save of an untouched form produces an empty
     * audit diff rather than a spurious "changed" entry.
     *
     * @return array<string, int|string>
     */
    private function stored(): array
    {
        $group = $this->settings->all(Setting::GROUP_SYSTEM);
        $bounds = $this->pricing->bounds();

        $values = [
            'booking_hold_minutes' => (int) ($group['booking_hold_minutes'] ?? config('booking.hold_minutes', 30)),
            'booking_verification_hold_minutes' => (int) ($group['booking_verification_hold_minutes'] ?? config('booking.verification_hold_minutes', 720)),
            'booking_code_prefix' => mb_strtoupper((string) (($group['booking_code_prefix'] ?? null) ?: config('booking.code_prefix', 'AH'))),
            // No config fallback: unlike the operations mailbox in config/booking.php,
            // this is a deliberately opt-in personal address that stays blank until
            // the owner types one in.
            'owner_notification_email' => (string) ($group['owner_notification_email'] ?? ''),
        ];

        foreach ($bounds['categories'] as $category) {
            $values[PricingService::rateKey($category['key'], PricingService::TIER_NON_PEAK)]
                = number_format($category['non_peak'], 2, '.', '');
            $values[PricingService::rateKey($category['key'], PricingService::TIER_PEAK)]
                = number_format($category['peak'], 2, '.', '');
        }

        $values['pricing_peak_start'] = $bounds['window']['peak']['start'];
        $values['pricing_peak_end'] = $bounds['window']['peak']['end'];

        return $values;
    }

    /**
     * Did any part of the pricing grid — any rate or the peak window — actually
     * move? A save that only touched the hold times or the code prefix must not
     * reprice the whole schedule. The keys checked are PricingService's own, so
     * this can never drift out of step with what a reprice reads.
     *
     * @param  array<string, int|string>  $before
     * @param  array<string, int|string>  $after
     */
    private function pricingChanged(array $before, array $after): bool
    {
        foreach (PricingService::settingKeys() as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record the bulk reprice as its own Slots/update entry so the audit trail
     * shows the schedule actually moved, alongside the Settings entry that shows
     * why. Skipped when the save changed no pricing, or changed a rate that no
     * live slot happened to be sitting at.
     *
     * @param  array{total: int, courts_changed: int, ...}|null  $repriced
     */
    private function recordRepriceAudit(?array $repriced): void
    {
        if ($repriced === null || $repriced['total'] === 0) {
            return;
        }

        $this->audit->log(
            module: 'Slots',
            action: 'update',
            description: sprintf(
                'Auto-repriced %s available %s across %s %s to the new rates after a pricing change.',
                number_format($repriced['total']),
                $repriced['total'] === 1 ? 'slot' : 'slots',
                number_format($repriced['courts_changed']),
                $repriced['courts_changed'] === 1 ? 'court' : 'courts',
            ),
            newValues: $repriced,
        );
    }

    /**
     * The flash line: plain confirmation for a save with no pricing effect, and
     * a count of what was synced when the reprice actually moved slots.
     *
     * @param  array{total: int, ...}|null  $repriced
     */
    private function savedMessage(?array $repriced): string
    {
        if ($repriced === null || $repriced['total'] === 0) {
            return 'Booking settings saved.';
        }

        return sprintf(
            'Booking settings saved. %s available %s across every court %s repriced to the new rates.',
            number_format($repriced['total']),
            $repriced['total'] === 1 ? 'slot' : 'slots',
            $repriced['total'] === 1 ? 'was' : 'were',
        );
    }

    /**
     * @param  array<string, int|string>  $before
     * @param  array<string, int|string>  $after
     */
    private function recordAudit(array $before, array $after): void
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) === $value) {
                continue;
            }

            $old[$key] = $before[$key] ?? null;
            $new[$key] = $value;
        }

        if ($new === []) {
            return;
        }

        $this->audit->log(
            module: 'Settings',
            action: 'update',
            description: 'Updated booking settings: '.implode(', ', array_keys($new)).'.',
            oldValues: $old,
            newValues: $new,
        );
    }
}
