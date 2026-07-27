<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Court;
use App\Models\CourtSlot;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\PricingService;

/**
 * The bulk slot generator.
 *
 * Slots in After Hours are entirely admin defined — there is no system-wide interval.
 * An operator describes a pattern ("weekdays, 6am to 10pm, 60 minute slots")
 * and this service materialises it across a date range.
 *
 * Pricing is club-wide (global), not a property of the run or the court. Each
 * row is priced by `PricingService::rateFor()` from the slot's own start time,
 * so one 7am–2am pass lays down non-peak and peak rates in a single go — and
 * because the peak window may cross midnight, a 1am row in a 4pm–2am peak run is
 * priced peak. `$params['price']` survives as an optional flat override for a
 * one-off promo run; when it is null the global rate table decides.
 *
 * Three properties matter more than speed:
 *
 *  1. **Idempotency.** Re-running the same pattern must never duplicate a slot.
 *     The `court_slots_unique_slot` index on (court_id, slot_date, start_time)
 *     is the authority; we insert with IGNORE and count what actually landed,
 *     rather than reading-then-writing and racing another operator.
 *  2. **Boundedness.** A year crossed with a 24-hour window at 5-minute slots is
 *     over 100k rows. `booking.max_generate_slots` is a hard ceiling and the
 *     run is refused — with a message that says how to fix it — before a single
 *     row is built.
 *  3. **Predictability.** `preview()` runs the identical computation without
 *     writing, so the UI can promise an exact number before the operator
 *     commits to it.
 */
class SlotGeneratorService
{
    /** Rows per INSERT statement. Large enough to be fast, small enough to not blow max_allowed_packet. */
    public const CHUNK_SIZE = 500;

    /** Longest span a single run may cover, in days. Guards the date loop itself. */
    public const MAX_RANGE_DAYS = 366;

    private const MINUTES_PER_DAY = 1440;

    public function __construct(private readonly PricingService $pricing) {}

    /**
     * Compute the run without touching the database beyond a read of what
     * already exists. Never throws for being too large — it reports it, so the
     * UI can show "5,040 slots — over the 2,000 limit" while the operator is
     * still tuning the form.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function preview(Court $court, array $params): array
    {
        $plan = $this->plan($params);
        $pricing = $this->pricing($plan, $this->overridePrice($params), $court->categoryKey());

        if ($plan['total'] === 0 || $plan['total'] > $plan['limit']) {
            return $this->summary($plan, existing: 0, created: 0, pricing: $pricing);
        }

        $existing = $this->countExisting($court, $plan);

        return $this->summary($plan, existing: $existing, created: $plan['total'] - $existing, pricing: $pricing);
    }

    /**
     * Materialise the pattern. Returns {created, skipped, total} plus the
     * diagnostics the flash message and audit entry are built from.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws ValidationException when the parameters are impossible or over the ceiling.
     */
    public function generate(Court $court, array $params): array
    {
        $plan = $this->plan($params);

        if ($plan['total'] === 0) {
            throw ValidationException::withMessages([
                'days_of_week' => [
                    'Those settings do not produce a single slot. Check the date range, the selected days and the opening hours.',
                ],
            ]);
        }

        if ($plan['total'] > $plan['limit']) {
            throw ValidationException::withMessages([
                'end_date' => [$this->limitMessage($plan)],
            ]);
        }

        $override = $this->overridePrice($params);
        $pricing = $this->pricing($plan, $override, $court->categoryKey());
        $timestamp = now();
        $created = 0;

        DB::transaction(function () use ($court, $plan, $override, $timestamp, &$created): void {
            foreach ($this->rowChunks($court, $plan, $override, $timestamp) as $chunk) {
                // IGNORE leans on the unique index: an existing (court, date,
                // start_time) is silently passed over, so re-running a pattern
                // only fills the gaps.
                $created += CourtSlot::query()->insertOrIgnore($chunk);
            }
        });

        return $this->summary($plan, existing: $plan['total'] - $created, created: $created, pricing: $pricing);
    }

    /**
     * The explicit, admin-triggered escape hatch from forward-only pricing.
     *
     * `generate()` prices a slot once, at the moment it is created, from
     * whatever the global rate table was that day — and by design never revisits
     * it afterwards (see SlotPricingTest::test_editing_the_rate_table_does_not_reprice_existing_slots).
     * That guarantee is correct for the passive act of *changing the rates in
     * Settings*. But an operator can also stand on the Slots screen and say, in
     * so many words, "push what I just set onto the schedule that already
     * exists" — that is this method. Nothing here fires on its own; it only
     * runs when a controller calls it in response to a deliberate click.
     *
     * The scope is deliberately narrow, on every axis:
     *
     *  - **Court.** Only `$court`'s own rows are ever touched.
     *  - **Status.** Only `available` rows. A slot a customer is mid-checkout
     *    on (`held`) or has already paid for (`booked`) must never have its
     *    price move under them; `blocked` rows are off the market anyway and
     *    are left exactly as an operator left them.
     *  - **Date.** Never earlier than today, regardless of what is asked for —
     *    the past cannot be rebooked, and reaching behind "today" would read
     *    as quietly rewriting history rather than adjusting the future. `$toDate`
     *    is an optional upper bound; `null` means "no ceiling".
     *
     * Two UPDATE statements total — one per tier — each scoped to the rows whose
     * start_time falls in that tier's window via `PricingService::constrainToTier()`,
     * which owns the wrap-around so a 4pm–2am peak window matches its post-midnight
     * rows too. Each statement carries a `price != rate` guard, so the returned
     * counts are genuine changes, not rows merely scanned, and a slot already
     * sitting at the current rate produces neither a write nor a stale "changed"
     * claim.
     *
     * `$withBreakdown` controls the per-tier "old average / matched" diagnostics
     * the manual per-court screen shows in its flash and audit line. They cost a
     * count() and an avg() per tier that the bulk repriceAll() throws away, so it
     * passes false to skip those round trips — the returned `total` (genuine
     * changes) is unaffected either way.
     *
     * @return array{
     *     court_id: int,
     *     from: string,
     *     to: ?string,
     *     total: int,
     *     breakdown: list<array{tier: string, rate: float, old_average: ?float, matched: int, count: int}>,
     * }
     */
    public function reprice(Court $court, ?string $fromDate = null, ?string $toDate = null, bool $withBreakdown = true): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $from = $this->repriceFromDate($fromDate, $today);
        $to = $this->repriceToDate($toDate);
        $courtId = $court->getKey();

        $breakdown = [];
        $total = 0;

        $category = $court->categoryKey();

        DB::transaction(function () use ($courtId, $category, $from, $to, $withBreakdown, &$breakdown, &$total): void {
            foreach ([PricingService::TIER_NON_PEAK, PricingService::TIER_PEAK] as $tier) {
                $rate = round($this->pricing->rateForTier($tier, $category), 2);

                $scope = fn () => $this->pricing->constrainToTier(
                    CourtSlot::query()
                        ->where('court_id', $courtId)
                        ->where('status', CourtSlot::STATUS_AVAILABLE)
                        ->where('slot_date', '>=', $from)
                        ->when($to !== null, fn ($q) => $q->where('slot_date', '<=', $to)),
                    $tier,
                );

                // What is already on the books for this tier, before the write —
                // used only to report a meaningful "old average" alongside the new
                // rate; never used to decide what to update. Skipped entirely when
                // the caller does not render the breakdown (the bulk reprice).
                $matched = $withBreakdown ? (int) $scope()->count() : 0;
                $oldAverage = ($withBreakdown && $matched > 0) ? round((float) $scope()->avg('price'), 2) : null;

                // The guard clause is the whole point: a row already at the
                // current rate is neither written nor counted as "changed".
                $updated = $scope()->where('price', '!=', $rate)->update([
                    'price' => $rate,
                    'updated_at' => now(),
                ]);

                $total += $updated;

                if (! $withBreakdown) {
                    continue;
                }

                $breakdown[] = [
                    'tier' => $tier,
                    'rate' => $rate,
                    'old_average' => $oldAverage,
                    'matched' => $matched,
                    'count' => $updated,
                ];
            }
        });

        return [
            'court_id' => $courtId,
            'from' => $from,
            'to' => $to,
            'total' => $total,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Push the current club-wide rates onto EVERY court's already-generated,
     * still-`available` schedule in one pass — the global equivalent of
     * reprice().
     *
     * This is what keeps the public schedule honest when the rate table itself
     * moves: pricing is club-wide, so a change in Settings is meant to apply
     * everywhere at once, without an operator opening each court's Slots screen
     * by hand. It delegates to reprice() per court, so every guarantee that
     * method makes holds here unchanged — only `available` rows, never before
     * today, and held / booked / blocked slots left exactly as they are.
     *
     * Every non-deleted court is included, active or not: `Court::query()`
     * already scopes soft-deleted courts out, and an inactive court is still
     * repriced so its schedule is correct the moment it is switched back on.
     *
     * @return array{
     *     from: string,
     *     to: ?string,
     *     courts: int,
     *     courts_changed: int,
     *     total: int,
     *     per_court: list<array{court_id: int, name: string, total: int}>,
     * }
     */
    public function repriceAll(?string $fromDate = null, ?string $toDate = null): array
    {
        $courts = Court::query()->orderBy('id')->get();

        $perCourt = [];
        $total = 0;
        $changed = 0;
        $from = null;
        $to = null;

        foreach ($courts as $court) {
            // withBreakdown: false — the aggregate only reports per-court totals,
            // so the per-tier count()/avg() diagnostics would be pure waste here.
            $summary = $this->reprice($court, $fromDate, $toDate, withBreakdown: false);

            // Every court resolves the same from/to (reprice() clamps both from
            // the same inputs), so the last one read is representative.
            $from = $summary['from'];
            $to = $summary['to'];
            $total += $summary['total'];

            if ($summary['total'] > 0) {
                $changed++;
            }

            $perCourt[] = [
                'court_id' => $summary['court_id'],
                'name' => (string) $court->name,
                'total' => $summary['total'],
            ];
        }

        return [
            'from' => $from ?? CarbonImmutable::today()->toDateString(),
            'to' => $to,
            'courts' => $courts->count(),
            'courts_changed' => $changed,
            'total' => $total,
            'per_court' => $perCourt,
        ];
    }

    /**
     * Clamp the requested lower bound to "not before today" — silently, rather
     * than rejecting it, since the caller (a form field defaulting to blank)
     * has no better answer to give and the guarantee matters more than the
     * complaint.
     */
    private function repriceFromDate(?string $fromDate, string $today): string
    {
        if ($fromDate === null || trim($fromDate) === '') {
            return $today;
        }

        try {
            $parsed = CarbonImmutable::parse(substr(trim($fromDate), 0, 10))->toDateString();
        } catch (\Throwable) {
            return $today;
        }

        // String comparison is safe here: both sides are "Y-m-d".
        return $parsed > $today ? $parsed : $today;
    }

    /** `null` means "no upper bound" — an absent or unparsable value reads the same way. */
    private function repriceToDate(?string $toDate): ?string
    {
        if ($toDate === null || trim($toDate) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr(trim($toDate), 0, 10))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /* --------------------------------------------------------------------- */
    /* Sync to operating hours                                                 */
    /* --------------------------------------------------------------------- */

    /**
     * Reshape EVERY court's future schedule to the club's operating window in one
     * pass — the deliberate, admin-triggered "make the slots match the hours I
     * set" action. It is to the operating hours what repriceAll() is to the rate
     * table: nothing about saving the hours in Settings triggers this on its own;
     * it only runs when a controller or the console command calls it.
     *
     * @return array{
     *     courts: int, courts_changed: int, added: int, removed: int, kept_history: int, blocked_in_window: int,
     *     skipped_dates: int, opening: string, closing: string,
     *     per_court: list<array{court_id: int, name: string, added: int, removed: int, kept_history: int, blocked_in_window: int, dates: int, skipped_dates: int}>,
     * }
     */
    public function syncToOperatingHours(string $opening, string $closing, int $duration = 60): array
    {
        // A window shorter than a single slot yields no valid start times. Refuse
        // here — loudly — rather than let an empty valid-set reach syncCourt()'s
        // delete, where an empty whereNotIn() compiles to `1 = 1` and would remove
        // every available slot on the schedule. This is the catastrophic case the
        // generate() path also guards (window < duration).
        if ($this->windowSlots($opening, $closing, $duration) === []) {
            throw ValidationException::withMessages([
                'operating_closing_time' => [
                    'The opening hours are shorter than a single slot, so there is nothing to sync. Widen the hours in Settings first.',
                ],
            ]);
        }

        $courts = Court::query()->orderBy('id')->get();

        $perCourt = [];
        $added = 0;
        $removed = 0;
        $kept = 0;
        $blockedInWindow = 0;
        $skipped = 0;
        $changed = 0;

        foreach ($courts as $court) {
            $summary = $this->syncCourt($court, $opening, $closing, $duration);

            $perCourt[] = $summary;
            $added += $summary['added'];
            $removed += $summary['removed'];
            $kept += $summary['kept_history'];
            $blockedInWindow += $summary['blocked_in_window'];
            $skipped += $summary['skipped_dates'];

            if ($summary['added'] > 0 || $summary['removed'] > 0 || $summary['kept_history'] > 0) {
                $changed++;
            }
        }

        return [
            'courts' => $courts->count(),
            'courts_changed' => $changed,
            'added' => $added,
            'removed' => $removed,
            'kept_history' => $kept,
            'blocked_in_window' => $blockedInWindow,
            'skipped_dates' => $skipped,
            'opening' => $opening,
            'closing' => $closing,
            'per_court' => $perCourt,
        ];
    }

    /**
     * Reshape one court's future schedule to the operating window.
     *
     * The scope is deliberately narrow, on every axis, so a live booking can
     * never be harmed:
     *
     *  - **Date.** Only today and later, and only dates that ALREADY carry at
     *    least one slot — the window says which HOURS are open, not which DAYS
     *    the club runs, so this never invents a schedule on a day that had none.
     *  - **Remove.** Only `available` rows whose start falls outside opening
     *    hours, and only those NO booking has ever referenced. Both booking
     *    foreign keys are `restrictOnDelete`, so a slot that ever carried a
     *    reservation — even one since cancelled or expired — cannot be deleted
     *    at all; those are BLOCKED instead (`kept_history`): off the market,
     *    row and history intact. A held, booked or blocked slot is never
     *    removed either way.
     *  - **Add.** Missing in-window hourly starts only, priced by the current
     *    global rate table exactly like generate(), inserted with IGNORE so a
     *    re-run is idempotent.
     *  - **Skip.** A date carrying a held or booked slot on an off-grid hour
     *    (not one of the window's hourly starts) is left entirely alone and
     *    counted as skipped — reshaping around it could overlap paid inventory,
     *    and that risk is never worth taking automatically. An off-grid BLOCKED
     *    slot skips the date only when its time range actually overlaps an
     *    in-window slot: blocked rows carry no customer, and the sync itself
     *    parks history-pinned slots as blocked at fully-outside hours — treating
     *    those as protection would freeze the date on every later run.
     *
     * Assumes an hourly ($duration-minute) schedule, which is what the generator
     * lays down by default and what the club runs.
     *
     * @return array{court_id: int, name: string, added: int, removed: int, kept_history: int, dates: int, skipped_dates: int}
     */
    public function syncCourt(Court $court, string $opening, string $closing, int $duration = 60): array
    {
        $windowSlots = $this->windowSlots($opening, $closing, $duration);
        $validStarts = array_column($windowSlots, 'start');
        $today = CarbonImmutable::today()->toDateString();
        $courtId = (int) $court->getKey();
        $category = $court->categoryKey();

        // Last line of defence: an empty valid-set must NEVER reach the
        // whereNotIn() delete below — the query grammar turns an empty NOT IN into
        // `1 = 1`, which would match and delete every available slot on the date.
        // Callers (and the request validation) prevent this upstream; this guard
        // guarantees a no-op even if one is ever bypassed.
        if ($validStarts === []) {
            return [
                'court_id' => $courtId,
                'name' => (string) $court->name,
                'added' => 0,
                'removed' => 0,
                'kept_history' => 0,
                'blocked_in_window' => 0,
                'dates' => 0,
                'skipped_dates' => 0,
            ];
        }

        // The window's slot intervals as linear minute pairs (end may exceed
        // 1440 when the window wraps midnight) — what the blocked-slot overlap
        // test below compares against.
        $windowIntervals = $this->windowIntervals($opening, $closing, $duration);

        $added = 0;
        $removed = 0;
        $keptHistory = 0;
        $blockedInWindow = 0;
        $datesTouched = 0;
        $datesSkipped = 0;

        DB::transaction(function () use (
            $courtId, $category, $windowSlots, $validStarts, $windowIntervals, $today,
            &$added, &$removed, &$keptHistory, &$blockedInWindow, &$datesTouched, &$datesSkipped
        ): void {
            // One price lookup per distinct in-window start, reused across dates.
            $prices = [];
            foreach ($validStarts as $start) {
                $prices[$start] ??= round($this->pricing->rateFor($start, $category), 2);
            }

            $timestamp = now();

            $dates = CourtSlot::query()
                ->where('court_id', $courtId)
                ->where('slot_date', '>=', $today)
                ->distinct()
                ->orderBy('slot_date')
                ->pluck('slot_date')
                ->map(static fn (mixed $d): string => substr((string) $d, 0, 10))
                ->all();

            foreach ($dates as $date) {
                $onDate = CourtSlot::query()
                    ->where('court_id', $courtId)
                    ->where('slot_date', $date)
                    ->get(['start_time', 'end_time', 'status']);

                // A held or booked slot on an off-grid hour means reshaping could
                // overlap paid inventory — leave the whole date alone. An off-grid
                // BLOCKED slot only protects the date when its range genuinely
                // overlaps an in-window slot: the sync itself parks history-pinned
                // rows as blocked at fully-outside hours (06:00 under a 07:00
                // window, say), and treating those as protection would freeze the
                // date on the very next run.
                $hasOffGridProtected = $onDate->contains(function (CourtSlot $s) use ($validStarts, $windowIntervals): bool {
                    if (in_array(substr((string) $s->start_time, 0, 8), $validStarts, true)) {
                        return false;
                    }

                    return match ($s->status) {
                        CourtSlot::STATUS_HELD, CourtSlot::STATUS_BOOKED => true,
                        CourtSlot::STATUS_BLOCKED => $this->overlapsWindow($s, $windowIntervals),
                        default => false,
                    };
                });

                if ($hasOffGridProtected) {
                    $datesSkipped++;

                    continue;
                }

                // Drop available slots now outside opening hours. Only `available`
                // — a held / booked / blocked row is never removed here — and only
                // rows NO booking ever referenced: both booking FKs are
                // restrictOnDelete, so deleting a history-pinned slot would abort
                // the whole sync with a constraint violation.
                $removedHere = CourtSlot::query()
                    ->where('court_id', $courtId)
                    ->where('slot_date', $date)
                    ->where('status', CourtSlot::STATUS_AVAILABLE)
                    ->whereNotIn('start_time', $validStarts)
                    ->withoutBookingHistory()
                    ->delete();

                // Whatever is still available outside the window after that
                // delete is exactly the history-pinned set — the rows the
                // database refuses to drop. Park them as `blocked` instead:
                // off the market, not bookable, but the row (and with it every
                // released booking's history) stays intact. `withBookingHistory()`
                // pins the target to genuinely-referenced rows, so a history-free
                // slot inserted concurrently between the delete and this update
                // (a parallel generate run, say) can never be swept up.
                $keptHere = CourtSlot::query()
                    ->where('court_id', $courtId)
                    ->where('slot_date', $date)
                    ->where('status', CourtSlot::STATUS_AVAILABLE)
                    ->whereNotIn('start_time', $validStarts)
                    ->withBookingHistory()
                    ->update(['status' => CourtSlot::STATUS_BLOCKED, 'updated_at' => $timestamp]);

                // Starts already present (any status). The rows just removed were
                // out-of-window, so they can never be a valid start — this is the
                // exact set of in-window hours that still need filling.
                $existing = $onDate
                    ->map(static fn (CourtSlot $s): string => substr((string) $s->start_time, 0, 8))
                    ->all();

                $rows = [];

                foreach ($windowSlots as $slot) {
                    if (in_array($slot['start'], $existing, true)) {
                        continue;
                    }

                    $rows[] = [
                        'court_id' => $courtId,
                        'slot_date' => $date,
                        'start_time' => $slot['start'],
                        'end_time' => $slot['end'],
                        'price' => $prices[$slot['start']],
                        'status' => CourtSlot::STATUS_AVAILABLE,
                        'held_booking_id' => null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                $addedHere = $rows !== [] ? CourtSlot::query()->insertOrIgnore($rows) : 0;

                $added += $addedHere;
                $removed += (int) $removedHere;
                $keptHistory += (int) $keptHere;

                if ($addedHere > 0 || $removedHere > 0 || $keptHere > 0) {
                    $datesTouched++;
                }
            }

            // Blocked slots that sit on an in-window hour: a slot parked as
            // blocked by a NARROWER window that the hours have since re-opened
            // over. The sync deliberately never auto-unblocks (it cannot tell
            // its own blocks from an operator's), so those hours stay off the
            // market until someone unblocks them by hand — surface a count so
            // "nothing changed" never hides a silently-missing bookable hour.
            $blockedInWindow = CourtSlot::query()
                ->where('court_id', $courtId)
                ->where('slot_date', '>=', $today)
                ->where('status', CourtSlot::STATUS_BLOCKED)
                ->whereIn('start_time', $validStarts)
                ->count();
        });

        return [
            'court_id' => $courtId,
            'name' => (string) $court->name,
            'added' => $added,
            'removed' => $removed,
            'kept_history' => $keptHistory,
            'blocked_in_window' => $blockedInWindow,
            'dates' => $datesTouched,
            'skipped_dates' => $datesSkipped,
        ];
    }

    /**
     * The in-window slot boundaries an hourly ($duration-minute) schedule would
     * have, as `{start, end}` "H:i:s" pairs — the same set generate() lays down.
     * Wrap-aware: a 07:00 → 02:00 window yields 07:00 … 01:00, crossing midnight.
     * `start_min` keeps the pre-wrap linear minute so windowIntervals() can
     * derive comparable ranges without re-deriving the wrap.
     *
     * @return list<array{start_min: int, start: string, end: string}>
     */
    private function windowSlots(string $opening, string $closing, int $duration): array
    {
        $open = $this->minutes($opening, 'opening_time');
        $close = $this->minutes($closing, 'closing_time');

        // A close at or before the open reads as "closes after midnight".
        if ($close <= $open) {
            $close += self::MINUTES_PER_DAY;
        }

        $perDay = $duration > 0 ? intdiv($close - $open, $duration) : 0;

        $slots = [];

        for ($i = 0; $i < $perDay; $i++) {
            $start = $open + ($i * $duration);

            $slots[] = [
                'start_min' => $start,
                'start' => $this->clock($start),
                'end' => $this->clock($start + $duration),
            ];
        }

        return $slots;
    }

    /**
     * The same in-window slots as linear minute ranges `[start, end)` — end may
     * exceed 1440 when the window wraps midnight. What overlapsWindow() compares
     * a blocked slot's clock range against.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function windowIntervals(string $opening, string $closing, int $duration): array
    {
        return array_map(
            static fn (array $slot): array => [(int) $slot['start_min'], (int) $slot['start_min'] + $duration],
            $this->windowSlots($opening, $closing, $duration),
        );
    }

    /**
     * Does this slot's clock range genuinely overlap any in-window slot?
     *
     * Both sides may wrap midnight, so the comparison runs on the 24-hour
     * circle: the slot's range is tried shifted a full day either way, and any
     * strict intersection with any window interval counts. A slot whose stored
     * times cannot be parsed is treated as overlapping — unreadable inventory
     * is protected, never reshaped around.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    private function overlapsWindow(CourtSlot $slot, array $intervals): bool
    {
        try {
            $start = $this->minutes(substr((string) $slot->start_time, 0, 8), 'start_time');
            $end = $this->minutes(substr((string) $slot->end_time, 0, 8), 'end_time');
        } catch (\Throwable) {
            return true;
        }

        // An end at or before the start means the slot runs past midnight —
        // the same convention as CourtSlot::endsAt().
        if ($end <= $start) {
            $end += self::MINUTES_PER_DAY;
        }

        foreach ($intervals as [$windowStart, $windowEnd]) {
            foreach ([-self::MINUTES_PER_DAY, 0, self::MINUTES_PER_DAY] as $shift) {
                if (max($start + $shift, $windowStart) < min($end + $shift, $windowEnd)) {
                    return true;
                }
            }
        }

        return false;
    }

    /* --------------------------------------------------------------------- */
    /* Planning                                                               */
    /* --------------------------------------------------------------------- */

    /**
     * Normalise and validate the raw parameters into an executable plan.
     *
     * @param  array<string, mixed>  $params
     * @return array{dates: list<string>, times: list<array{start: string, end: string}>, days: int, slots_per_day: int, total: int, limit: int, duration: int}
     */
    private function plan(array $params): array
    {
        $start = $this->date($params['start_date'] ?? null, 'start_date');
        $end = $this->date($params['end_date'] ?? null, 'end_date');

        if ($end->lessThan($start)) {
            throw ValidationException::withMessages([
                'end_date' => ['The end date must be on or after the start date.'],
            ]);
        }

        $span = $start->diffInDays($end) + 1;

        if ($span > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'end_date' => [
                    'A single generator run may cover at most '.self::MAX_RANGE_DAYS.' days. Split the schedule into shorter runs.',
                ],
            ]);
        }

        $duration = (int) ($params['duration_minutes'] ?? 0);

        if ($duration < 5 || $duration > self::MINUTES_PER_DAY) {
            throw ValidationException::withMessages([
                'duration_minutes' => ['The slot duration must be between 5 and 1440 minutes.'],
            ]);
        }

        $opening = $this->minutes($params['opening_time'] ?? null, 'opening_time');
        $closing = $this->minutes($params['closing_time'] ?? null, 'closing_time');

        // A closing time at or before the opening time reads as "we close after
        // midnight" — 18:00 to 02:00 is a perfectly ordinary evening session.
        if ($closing <= $opening) {
            $closing += self::MINUTES_PER_DAY;
        }

        $window = $closing - $opening;

        if ($window < $duration) {
            throw ValidationException::withMessages([
                'duration_minutes' => [
                    'The slot duration is longer than the opening window, so no slot fits. Shorten the duration or widen the hours.',
                ],
            ]);
        }

        // Integer division discards the trailing partial slot: a 07:00–12:30
        // window at 60 minutes yields five slots and stops at 12:00.
        $perDay = intdiv($window, $duration);

        $times = [];

        for ($i = 0; $i < $perDay; $i++) {
            $slotStart = $opening + ($i * $duration);

            $times[] = [
                'start' => $this->clock($slotStart),
                'end' => $this->clock($slotStart + $duration),
            ];
        }

        $mask = $this->dayMask($params['days_of_week'] ?? null);

        $dates = [];
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            if (in_array($cursor->dayOfWeek, $mask, true)) {
                $dates[] = $cursor->toDateString();
            }

            $cursor = $cursor->addDay();
        }

        return [
            'dates' => $dates,
            'times' => $times,
            'days' => count($dates),
            'slots_per_day' => $perDay,
            'total' => count($dates) * $perDay,
            'limit' => $this->limit(),
            'duration' => $duration,
        ];
    }

    /**
     * How many of the planned (date, start_time) pairs are already on the books.
     * Only ever called for a plan already known to be within the ceiling, so the
     * result set is bounded by `max_generate_slots`.
     *
     * @param  array{dates: list<string>, times: list<array{start: string, end: string}>, ...}  $plan
     */
    private function countExisting(Court $court, array $plan): int
    {
        if ($plan['dates'] === [] || $plan['times'] === []) {
            return 0;
        }

        $starts = array_column($plan['times'], 'start');

        $rows = DB::table('court_slots')
            ->where('court_id', $court->getKey())
            ->whereBetween('slot_date', [$plan['dates'][0], $plan['dates'][array_key_last($plan['dates'])]])
            ->whereIn('start_time', $starts)
            ->get(['slot_date', 'start_time']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $taken = [];

        foreach ($rows as $row) {
            $date = substr((string) $row->slot_date, 0, 10);
            $time = substr((string) $row->start_time, 0, 8);
            $taken[$date.'|'.$time] = true;
        }

        $existing = 0;

        foreach ($plan['dates'] as $date) {
            foreach ($starts as $time) {
                if (isset($taken[$date.'|'.$time])) {
                    $existing++;
                }
            }
        }

        return $existing;
    }

    /**
     * Yield insert-ready rows in fixed-size chunks so a 2,000 slot run never
     * builds a 2,000 element array in memory at once.
     *
     * @param  array{dates: list<string>, times: list<array{start: string, end: string}>, ...}  $plan
     * @param  float|null  $override  flat price for the whole run, or null to price from the court
     * @return Generator<int, list<array<string, mixed>>>
     */
    private function rowChunks(Court $court, array $plan, ?float $override, mixed $timestamp): Generator
    {
        $courtId = $court->getKey();
        // One price lookup per distinct start time rather than one per row: a
        // 2,000 slot run resolves at most `slots_per_day` rates.
        $prices = $this->pricesByStartTime($plan, $override, $court->categoryKey());
        $chunk = [];

        foreach ($plan['dates'] as $date) {
            foreach ($plan['times'] as $time) {
                $chunk[] = [
                    'court_id' => $courtId,
                    'slot_date' => $date,
                    'start_time' => $time['start'],
                    'end_time' => $time['end'],
                    'price' => $prices[$time['start']],
                    'status' => CourtSlot::STATUS_AVAILABLE,
                    'held_booking_id' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                if (count($chunk) >= self::CHUNK_SIZE) {
                    yield $chunk;
                    $chunk = [];
                }
            }
        }

        if ($chunk !== []) {
            yield $chunk;
        }
    }

    /* --------------------------------------------------------------------- */
    /* Pricing                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * The flat override, if the caller supplied one. A present-but-null price
     * (or an absent key) means "let the court's rates decide"; only a real
     * number overrides. Empty strings — what a cleared form field sends — read
     * as "no override", never as ₱0.
     */
    private function overridePrice(array $params): ?float
    {
        $value = $params['price'] ?? null;

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * Resolve the price for every distinct start time in the plan, once.
     *
     * With an override in force every start time maps to that one figure;
     * otherwise each maps to `PricingService::rateFor()` for that clock time and
     * the court's category, so the same pass produces non-peak- and peak-priced
     * rows at the rates that category charges.
     *
     * @param  array{times: list<array{start: string, end: string}>, ...}  $plan
     * @return array<string, float>  keyed by the "H:i:s" start time
     */
    private function pricesByStartTime(array $plan, ?float $override, string $category): array
    {
        $prices = [];

        foreach ($plan['times'] as $time) {
            $start = $time['start'];

            $prices[$start] ??= $override ?? $this->pricing->rateFor($start, $category);
        }

        return $prices;
    }

    /**
     * The pricing breakdown the preview and audit trail report: what source
     * decided the price, and — when the global rate table drives it — how many
     * slots and how much money fall in each tier. Built from the plan, so it
     * costs nothing beyond the resolution the write path already does.
     *
     * @param  array{times: list<array{start: string, end: string}>, days: int, ...}  $plan
     * @return array<string, mixed>
     */
    private function pricing(array $plan, ?float $override, string $category): array
    {
        $days = $plan['days'];

        // Tally each tier across one day's start times, then scale by the
        // number of dates the pattern actually lands on.
        $tiers = [
            PricingService::TIER_NON_PEAK => ['count' => 0, 'rate' => null],
            PricingService::TIER_PEAK => ['count' => 0, 'rate' => null],
        ];

        foreach ($plan['times'] as $time) {
            $tier = $this->pricing->tierFor($time['start']);
            $price = $override ?? $this->pricing->rateFor($time['start'], $category);

            $tiers[$tier]['count']++;
            $tiers[$tier]['rate'] = $price;
        }

        $breakdown = [];

        foreach ($tiers as $tier => $data) {
            if ($data['count'] === 0) {
                continue;
            }

            $slots = $data['count'] * $days;

            $breakdown[] = [
                'tier' => $tier,
                'rate' => round((float) $data['rate'], 2),
                'slots' => $slots,
                'subtotal' => round((float) $data['rate'] * $slots, 2),
            ];
        }

        return [
            // 'override' — one flat price; 'category' — driven by the rate table
            // row this court's category sits on.
            'source' => $override !== null ? 'override' : 'category',
            'override' => $override,
            'category' => $category,
            'category_label' => Court::labelForCategory($category),
            'breakdown' => $breakdown,
        ];
    }

    /* --------------------------------------------------------------------- */
    /* Helpers                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * @param  array{days: int, slots_per_day: int, total: int, limit: int, duration: int, ...}  $plan
     * @param  array<string, mixed>  $pricing
     * @return array<string, mixed>
     */
    private function summary(array $plan, int $existing, int $created, array $pricing = []): array
    {
        return [
            'total' => $plan['total'],
            'created' => max(0, $created),
            'skipped' => max(0, $existing),
            'existing' => max(0, $existing),
            'days' => $plan['days'],
            'slots_per_day' => $plan['slots_per_day'],
            'duration_minutes' => $plan['duration'],
            'limit' => $plan['limit'],
            'exceeds_limit' => $plan['total'] > $plan['limit'],
            'message' => $plan['total'] > $plan['limit'] ? $this->limitMessage($plan) : null,
            'pricing' => $pricing,
        ];
    }

    /** @param array{total: int, limit: int, days: int, slots_per_day: int, ...} $plan */
    private function limitMessage(array $plan): string
    {
        return sprintf(
            'These settings would create %s slots (%s days × %s per day), which is over the limit of %s for a single run. Narrow the date range, shorten the opening hours, or use a longer slot duration.',
            number_format($plan['total']),
            number_format($plan['days']),
            number_format($plan['slots_per_day']),
            number_format($plan['limit']),
        );
    }

    private function limit(): int
    {
        $limit = (int) config('booking.max_generate_slots', 2000);

        return $limit > 0 ? $limit : 2000;
    }

    /**
     * Day-of-week mask, 0 (Sunday) through 6 (Saturday) to match both Carbon's
     * `dayOfWeek` and JavaScript's `Date#getDay`. An empty mask means every day.
     *
     * @return list<int>
     */
    private function dayMask(mixed $days): array
    {
        if (! is_array($days) || $days === []) {
            return [0, 1, 2, 3, 4, 5, 6];
        }

        $mask = [];

        foreach ($days as $day) {
            $value = (int) $day;

            if ($value >= 0 && $value <= 6 && ! in_array($value, $mask, true)) {
                $mask[] = $value;
            }
        }

        if ($mask === []) {
            throw ValidationException::withMessages([
                'days_of_week' => ['Select at least one day of the week.'],
            ]);
        }

        sort($mask);

        return $mask;
    }

    private function date(mixed $value, string $field): CarbonImmutable
    {
        $raw = is_string($value) ? trim($value) : '';

        if ($raw === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            throw ValidationException::withMessages([
                $field => ['Enter a valid date in YYYY-MM-DD format.'],
            ]);
        }

        try {
            return CarbonImmutable::parse(substr($raw, 0, 10))->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => ['Enter a valid date in YYYY-MM-DD format.'],
            ]);
        }
    }

    /** Minutes since midnight for an "H:i" or "H:i:s" clock string. */
    private function minutes(mixed $value, string $field): int
    {
        $raw = is_string($value) ? trim($value) : '';

        if (! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
            throw ValidationException::withMessages([
                $field => ['Enter a valid time in 24-hour HH:MM format.'],
            ]);
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];

        if ($hours > 23 || $minutes > 59) {
            throw ValidationException::withMessages([
                $field => ['Enter a valid time in 24-hour HH:MM format.'],
            ]);
        }

        return ($hours * 60) + $minutes;
    }

    /** Minutes since midnight back to the "H:i:s" string the TIME column stores. */
    private function clock(int $minutes): string
    {
        $wrapped = (($minutes % self::MINUTES_PER_DAY) + self::MINUTES_PER_DAY) % self::MINUTES_PER_DAY;

        return sprintf('%02d:%02d:00', intdiv($wrapped, 60), $wrapped % 60);
    }
}
