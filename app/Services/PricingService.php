<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Court;
use App\Models\Setting;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Court pricing.
 *
 * The rate table is a GRID, not a single number: one row per court category
 * (App\Models\Court::CATEGORIES — a full-size court and the Skinny Court charge
 * very different money) crossed with two time tiers, non-peak and peak. Four
 * rates in all, and they live in settings (Admin > Settings > Booking) rather
 * than on the court row, so adding a third full-size court is a court record
 * and not a pricing change.
 *
 * The peak WINDOW is shared by every category — the club's evening band starts
 * at the same clock time whichever court you are on — so it is one pair of
 * settings, not one per category. Non-peak is always the exact complement.
 *
 * The one thing this class exists to get right is the midnight wrap. A window
 * of 5:00 PM to midnight has `peak_start` (17:00) LATER in the day than
 * `peak_end` (00:00), so a naive `start <= t < end` would match nothing.
 * `inWindow()` treats a window whose start is after its end as running through
 * midnight — which is also what lets a club trading to 2 AM set `peak_end` to
 * 02:00 and have a 1 AM slot price as peak, with no code change.
 *
 * A saved row in the `system` group overrides each value; `config/booking.php`
 * is only the shipped default for a fresh install. Every read falls back to
 * that config so the service works before the settings are ever seeded (and in
 * tests that do not seed them).
 */
class PricingService
{
    public const TIER_NON_PEAK = 'non_peak';

    public const TIER_PEAK = 'peak';

    /** Both tiers, in the order every screen renders them. */
    public const TIERS = [self::TIER_NON_PEAK, self::TIER_PEAK];

    private const MINUTES_PER_DAY = 1440;

    /**
     * The two clock settings, mapped to their config fallback. Rates are NOT
     * listed here — there is one per (category, tier) pair and they are derived
     * from Court::CATEGORIES by rateKey(), so a new category can never be added
     * to the model and forgotten here.
     *
     * @var array<string, string>
     */
    public const WINDOW_KEYS = [
        'pricing_peak_start' => 'booking.pricing.peak_start',
        'pricing_peak_end' => 'booking.pricing.peak_end',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /* --------------------------------------------------------------------- */
    /* Key naming                                                             */
    /* --------------------------------------------------------------------- */

    /**
     * The settings key holding one cell of the grid, e.g.
     * ('skinny', 'peak') => "pricing_skinny_peak_rate".
     *
     * One method owns this shape so the reader, the settings screen, the
     * validator and the seeder cannot spell the same key four different ways.
     */
    public static function rateKey(string $category, string $tier): string
    {
        return sprintf('pricing_%s_%s_rate', $category, $tier);
    }

    /**
     * Every settings key this service reads — all four rates followed by the
     * two window boundaries. What SystemSettingsController diffs to decide
     * whether a save actually moved the pricing and therefore needs a reprice.
     *
     * @return list<string>
     */
    public static function settingKeys(): array
    {
        $keys = [];

        foreach (Court::categoryKeys() as $category) {
            foreach (self::TIERS as $tier) {
                $keys[] = self::rateKey($category, $tier);
            }
        }

        return [...$keys, ...array_keys(self::WINDOW_KEYS)];
    }

    /* --------------------------------------------------------------------- */
    /* Reading the table                                                      */
    /* --------------------------------------------------------------------- */

    /**
     * One category's two rates, each rounded to the peso-and-centavo the money
     * column stores.
     *
     * @return array{non_peak: float, peak: float}
     */
    public function rates(string $category): array
    {
        return [
            self::TIER_NON_PEAK => $this->rate($category, self::TIER_NON_PEAK),
            self::TIER_PEAK => $this->rate($category, self::TIER_PEAK),
        ];
    }

    /**
     * The whole grid, keyed by category.
     *
     * @return array<string, array{non_peak: float, peak: float}>
     */
    public function allRates(): array
    {
        $grid = [];

        foreach (Court::categoryKeys() as $category) {
            $grid[$category] = $this->rates($category);
        }

        return $grid;
    }

    /**
     * The full config the settings screen renders and the generator reports:
     * the shared window, plus every category's label and pair of rates.
     *
     * @return array{
     *     window: array{
     *         peak: array{start: string, end: string},
     *         non_peak: array{start: string, end: string},
     *     },
     *     categories: list<array{key: string, label: string, non_peak: float, peak: float}>,
     * }
     */
    public function bounds(): array
    {
        $peakStart = $this->time('pricing_peak_start');
        $peakEnd = $this->time('pricing_peak_end');

        $categories = [];

        foreach (Court::CATEGORIES as $key => $label) {
            $rates = $this->rates($key);

            $categories[] = [
                'key' => $key,
                'label' => $label,
                'non_peak' => $rates[self::TIER_NON_PEAK],
                'peak' => $rates[self::TIER_PEAK],
            ];
        }

        return [
            'window' => [
                'peak' => ['start' => $peakStart, 'end' => $peakEnd],
                // Non-peak is every hour OUTSIDE peak, so its window is always
                // the exact complement — derived here, never stored, so the
                // hours a screen shows can never disagree with the tier this
                // service actually charges.
                'non_peak' => ['start' => $peakEnd, 'end' => $peakStart],
            ],
            'categories' => $categories,
        ];
    }

    /**
     * The lowest rate on offer anywhere — what the public site shows as
     * "From ₱X" when it has no particular court in hand.
     */
    public function fromRate(): float
    {
        $all = [];

        foreach ($this->allRates() as $rates) {
            $all[] = $rates[self::TIER_NON_PEAK];
            $all[] = $rates[self::TIER_PEAK];
        }

        return $all === [] ? 0.0 : round(min($all), 2);
    }

    /**
     * The lowest rate one category charges — the honest "from" figure for a
     * specific court's card, since a Skinny Court must never advertise a
     * full-size court's price or the reverse.
     */
    public function fromRateFor(string $category): float
    {
        $rates = $this->rates($category);

        return round(min($rates[self::TIER_NON_PEAK], $rates[self::TIER_PEAK]), 2);
    }

    /* --------------------------------------------------------------------- */
    /* Resolving one slot                                                     */
    /* --------------------------------------------------------------------- */

    /**
     * Which tier a slot starting at the given time falls in. The peak window
     * decides; everything outside it — including the small hours nobody usually
     * opens — is non-peak. Accepts a bare "HH:MM"/"HH:MM:SS" clock string, a
     * full datetime string, or any DateTimeInterface, and never throws:
     * anything it cannot read as a time is treated as non-peak.
     *
     * The tier is category-independent by design: the club's evening band
     * starts at the same clock time on every court, only the money differs.
     *
     * @return 'non_peak'|'peak'
     */
    public function tierFor(string|DateTimeInterface $startTime): string
    {
        $minutes = $this->minutesOf($startTime);

        if ($minutes === null) {
            return self::TIER_NON_PEAK;
        }

        return $this->inWindow($minutes, $this->minutes('pricing_peak_start'), $this->minutes('pricing_peak_end'))
            ? self::TIER_PEAK
            : self::TIER_NON_PEAK;
    }

    /**
     * The rate that applies to a slot on the given court category, starting at
     * the given time — the one call the slot generator makes per distinct hour.
     */
    public function rateFor(string|DateTimeInterface $startTime, string $category): float
    {
        return $this->rateForTier($this->tierFor($startTime), $category);
    }

    /**
     * The rate for a named tier and category. Base-cased on both axes so an
     * unknown tier prices at non-peak and an unknown category at the normal
     * court's rate, rather than throwing mid-booking.
     */
    public function rateForTier(string $tier, string $category): float
    {
        return $this->rate(
            array_key_exists($category, Court::CATEGORIES) ? $category : Court::CATEGORY_NORMAL,
            $tier === self::TIER_PEAK ? self::TIER_PEAK : self::TIER_NON_PEAK,
        );
    }

    /**
     * Human label for a tier, e.g. for the audit trail and the generator
     * breakdown.
     */
    public static function label(string $tier): string
    {
        return $tier === self::TIER_PEAK ? 'Peak' : 'Non-peak';
    }

    /* --------------------------------------------------------------------- */
    /* Constraining a query                                                   */
    /* --------------------------------------------------------------------- */

    /**
     * Narrow a court_slots query to the rows whose start_time falls in (or, for
     * non-peak, out of) the peak window — the wrap-aware SQL the bulk reprice
     * leans on. Non-peak is expressed as the exact complement of peak so a row
     * can never match both tiers or neither.
     *
     * Category plays no part here: the caller has already scoped the query to
     * one court, and that court's category decides the money, not which rows
     * belong to which tier.
     *
     * @param  Builder<\App\Models\CourtSlot>  $query
     * @param  'non_peak'|'peak'  $tier
     * @return Builder<\App\Models\CourtSlot>
     */
    public function constrainToTier(Builder $query, string $tier): Builder
    {
        $start = $this->clock($this->minutes('pricing_peak_start'));
        $end = $this->clock($this->minutes('pricing_peak_end'));

        // A zero-width peak window (start == end) means "no peak at all": peak
        // matches nothing, non-peak matches everything.
        if ($start === $end) {
            return $tier === self::TIER_PEAK ? $query->whereRaw('1 = 0') : $query;
        }

        $wraps = $start > $end;

        if ($tier === self::TIER_PEAK) {
            return $wraps
                ? $query->where(fn (Builder $q) => $q->where('start_time', '>=', $start)->orWhere('start_time', '<', $end))
                : $query->where('start_time', '>=', $start)->where('start_time', '<', $end);
        }

        // Non-peak = NOT in the peak window.
        return $wraps
            ? $query->where('start_time', '<', $start)->where('start_time', '>=', $end)
            : $query->where(fn (Builder $q) => $q->where('start_time', '<', $start)->orWhere('start_time', '>=', $end));
    }

    /* --------------------------------------------------------------------- */
    /* Internals                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * True when `minutes` lands inside the half-open window [start, end),
     * treating a window whose start is after its end as running through
     * midnight (07:00→16:00 is a normal window; 17:00→00:00 wraps).
     */
    private function inWindow(int $minutes, int $start, int $end): bool
    {
        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? $minutes >= $start && $minutes < $end
            : $minutes >= $start || $minutes < $end;
    }

    /**
     * One cell of the rate grid as a rounded float, falling back to its config
     * default. A category with neither a saved row nor a config entry prices at
     * zero — visible and wrong on the settings screen, rather than fatal on the
     * public booking page.
     */
    private function rate(string $category, string $tier): float
    {
        $stored = $this->settings->get(Setting::GROUP_SYSTEM, self::rateKey($category, $tier));

        $value = ($stored === null || $stored === '')
            ? config(sprintf('booking.pricing.categories.%s.%s_rate', $category, $tier), 0)
            : $stored;

        return round((float) $value, 2);
    }

    /** A window-boundary setting as a normalised "HH:MM" string. */
    private function time(string $key): string
    {
        return $this->clockShort($this->minutes($key));
    }

    /** A window-boundary setting as minutes since midnight, 0–1439. */
    private function minutes(string $key): int
    {
        $stored = $this->settings->get(Setting::GROUP_SYSTEM, $key);
        $raw = ($stored === null || $stored === '')
            ? (string) config(self::WINDOW_KEYS[$key] ?? '', '')
            : (string) $stored;

        return $this->minutesOf($raw) ?? 0;
    }

    /**
     * Minutes since midnight for anything time-shaped — a clock string, a
     * datetime string, or a DateTimeInterface — or null when it is not a time.
     */
    private function minutesOf(string|DateTimeInterface $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return ((int) $value->format('G') * 60) + (int) $value->format('i');
        }

        // Matches a bare clock ('9:30', '09:30:00') and the leading clock of a
        // full datetime ('2026-07-21 09:30:00') alike.
        if (preg_match('/(?:^|[\sT])(\d{1,2}):(\d{2})/', trim($value), $m) !== 1) {
            return null;
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    /** Minutes since midnight to the "HH:MM:SS" a TIME column stores. */
    private function clock(int $minutes): string
    {
        $wrapped = (($minutes % self::MINUTES_PER_DAY) + self::MINUTES_PER_DAY) % self::MINUTES_PER_DAY;

        return sprintf('%02d:%02d:00', intdiv($wrapped, 60), $wrapped % 60);
    }

    /** Minutes since midnight to the "HH:MM" the settings form round-trips. */
    private function clockShort(int $minutes): string
    {
        $wrapped = (($minutes % self::MINUTES_PER_DAY) + self::MINUTES_PER_DAY) % self::MINUTES_PER_DAY;

        return sprintf('%02d:%02d', intdiv($wrapped, 60), $wrapped % 60);
    }
}
