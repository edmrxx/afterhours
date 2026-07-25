<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Club-wide court pricing.
 *
 * Pricing in PHEA is GLOBAL, not per court: every court charges the same, so the
 * rate table lives in settings (Admin > Settings > Booking) rather than on the
 * court row. There are exactly two tiers — non-peak and peak — and each owns a
 * money rate plus a clock window the admin can move.
 *
 * The one thing this class exists to get right is the midnight wrap. The default
 * peak window is 4:00 PM to 2:00 AM: `peak_start` (16:00) is LATER in the day
 * than `peak_end` (02:00), so a naive `start <= t < end` would match nothing.
 * `inWindow()` treats a window whose start is after its end as running through
 * midnight, which is what lets a 1 AM slot be priced as peak.
 *
 * A saved row in the `system` group overrides each value; `config/booking.php`
 * is only the shipped default for a fresh install. Every read falls back to that
 * config so the service works before the settings are ever seeded (and in tests
 * that do not seed them).
 */
class PricingService
{
    public const TIER_NON_PEAK = 'non_peak';

    public const TIER_PEAK = 'peak';

    private const MINUTES_PER_DAY = 1440;

    /**
     * Settings key => [config path, is it a rate?]. One list so the reader,
     * the settings screen and the seeder cannot name a key three different ways.
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    public const KEYS = [
        'pricing_non_peak_rate' => ['booking.pricing.non_peak_rate', true],
        'pricing_peak_rate' => ['booking.pricing.peak_rate', true],
        'pricing_peak_start' => ['booking.pricing.peak_start', false],
        'pricing_peak_end' => ['booking.pricing.peak_end', false],
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /* --------------------------------------------------------------------- */
    /* Reading the table                                                      */
    /* --------------------------------------------------------------------- */

    /**
     * The two rates, each rounded to the peso-and-centavo the money column
     * stores.
     *
     * @return array{non_peak: float, peak: float}
     */
    public function rates(): array
    {
        return [
            self::TIER_NON_PEAK => $this->rate('pricing_non_peak_rate'),
            self::TIER_PEAK => $this->rate('pricing_peak_rate'),
        ];
    }

    /**
     * The full config the settings screen renders and the generator reports:
     * both rates and both "HH:MM" windows.
     *
     * @return array{
     *     non_peak: array{rate: float, start: string, end: string},
     *     peak: array{rate: float, start: string, end: string},
     * }
     */
    public function bounds(): array
    {
        return [
            self::TIER_NON_PEAK => [
                'rate' => $this->rate('pricing_non_peak_rate'),
                // Non-peak is every hour OUTSIDE peak, so its window is always the
                // exact complement of the peak window — derived here, never read
                // from its own stored start/end, so the hours a screen shows can
                // never disagree with the tier PricingService actually charges.
                'start' => $this->time('pricing_peak_end'),
                'end' => $this->time('pricing_peak_start'),
            ],
            self::TIER_PEAK => [
                'rate' => $this->rate('pricing_peak_rate'),
                'start' => $this->time('pricing_peak_start'),
                'end' => $this->time('pricing_peak_end'),
            ],
        ];
    }

    /**
     * The lowest rate on offer — what the public site shows as "From ₱X".
     */
    public function fromRate(): float
    {
        $rates = $this->rates();

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
     * The rate that applies to a slot starting at the given time.
     */
    public function rateFor(string|DateTimeInterface $startTime): float
    {
        return $this->rateForTier($this->tierFor($startTime));
    }

    /**
     * The rate for a named tier, base-cased so an unknown tier prices at
     * non-peak rather than throwing.
     */
    public function rateForTier(string $tier): float
    {
        return $tier === self::TIER_PEAK
            ? $this->rate('pricing_peak_rate')
            : $this->rate('pricing_non_peak_rate');
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
     * midnight (07:00→16:00 is a normal window; 16:00→02:00 wraps).
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

    /** A rate setting as a rounded float, falling back to its config default. */
    private function rate(string $key): float
    {
        [$configPath] = self::KEYS[$key];

        $stored = $this->settings->get(Setting::GROUP_SYSTEM, $key);
        $value = ($stored === null || $stored === '') ? config($configPath) : $stored;

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
        [$configPath] = self::KEYS[$key];

        $stored = $this->settings->get(Setting::GROUP_SYSTEM, $key);
        $raw = ($stored === null || $stored === '') ? (string) config($configPath) : (string) $stored;

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
