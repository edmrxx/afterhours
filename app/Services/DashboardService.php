<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read model behind the admin dashboard.
 *
 * Every figure on the screen is produced by an aggregate query — GROUP BY and
 * conditional SUM in SQL, never a collection walked in PHP. Current and prior
 * periods are folded into a single round trip with CASE expressions so a stat
 * card costs one query rather than two, and the whole payload is memoised for
 * a minute so a dashboard left open on a wall display does not hammer MySQL.
 */
class DashboardService
{
    /** Ranges the chart switcher may request, in days. */
    public const RANGES = [7, 30, 90];

    public const DEFAULT_RANGE = 30;

    /** Seconds the aggregate payloads stay warm. */
    private const CACHE_TTL = 60;

    private const CACHE_PREFIX = 'phea.dashboard';

    /** Latest audit entries shown in the activity feed. */
    private const ACTIVITY_LIMIT = 8;

    /** Confirmed bookings shown in the "up next" list. */
    private const UPCOMING_LIMIT = 5;

    /** Courts plotted on the per-court bar chart before we stop. */
    private const COURT_LIMIT = 12;

    private const PESO = "\u{20B1}";

    /**
     * Everything the dashboard needs on first paint.
     *
     * @return array{stats: array<string, mixed>, activity: list<array<string, mixed>>, upcoming: list<array<string, mixed>>, generated_at: string}
     */
    public function overview(): array
    {
        return Cache::remember(
            self::CACHE_PREFIX.'.overview',
            self::CACHE_TTL,
            function (): array {
                $now = Carbon::now();

                return [
                    'stats' => $this->stats($now),
                    'activity' => $this->recentActivity(),
                    'upcoming' => $this->upcomingBookings($now),
                    'generated_at' => $now->toIso8601String(),
                ];
            }
        );
    }

    /**
     * The four chart datasets for a given window. Driven by the range switch,
     * so it is cached per range rather than alongside the overview.
     *
     * @return array{days: int, range_label: string, revenue_trend: array<string, mixed>, status_mix: array<string, mixed>, per_court: array<string, mixed>, peak_hours: array<string, mixed>, generated_at: string}
     */
    public function charts(int $days): array
    {
        $days = $this->normaliseRange($days);

        return Cache::remember(
            self::CACHE_PREFIX.'.charts.'.$days,
            self::CACHE_TTL,
            function () use ($days): array {
                $now = Carbon::now();
                $from = $now->copy()->startOfDay()->subDays($days - 1);

                return [
                    'days' => $days,
                    'range_label' => 'Last '.$days.' days',
                    'revenue_trend' => $this->revenueTrend($from, $now, $days),
                    'status_mix' => $this->statusMix($from),
                    'per_court' => $this->bookingsPerCourt($from),
                    'peak_hours' => $this->peakHours($from),
                    'generated_at' => $now->toIso8601String(),
                ];
            }
        );
    }

    /**
     * Clamp an arbitrary request value onto the supported windows.
     */
    public function normaliseRange(?int $days): int
    {
        return in_array($days, self::RANGES, true) ? $days : self::DEFAULT_RANGE;
    }

    /**
     * Drop every memoised payload. Call after a bulk write if a screen must
     * reflect it immediately rather than within the minute.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_PREFIX.'.overview');

        foreach (self::RANGES as $range) {
            Cache::forget(self::CACHE_PREFIX.'.charts.'.$range);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Stat cards                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function stats(Carbon $now): array
    {
        return [
            'bookings_today' => $this->bookingsTodayStat($now),
            'pending_verification' => $this->pendingVerificationStat($now),
            'revenue_month' => $this->revenueMonthStat($now),
            'utilisation_week' => $this->utilisationWeekStat($now),
        ];
    }

    /**
     * Bookings created today against the same figure yesterday. One scan of a
     * two-day window rather than two separate counts.
     *
     * @return array<string, mixed>
     */
    private function bookingsTodayStat(Carbon $now): array
    {
        $todayStart = $now->copy()->startOfDay();
        $tomorrowStart = $todayStart->copy()->addDay();
        $yesterdayStart = $todayStart->copy()->subDay();

        $row = Booking::query()
            ->where('created_at', '>=', $yesterdayStart)
            ->where('created_at', '<', $tomorrowStart)
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) AS current_total', [$todayStart])
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END), 0) AS previous_total', [$todayStart])
            ->first();

        $current = (int) ($row->current_total ?? 0);
        $previous = (int) ($row->previous_total ?? 0);

        return [
            'value' => $current,
            'display' => number_format($current),
            'previous' => $previous,
            'hint' => 'vs. '.number_format($previous).' yesterday',
            ...$this->percentDelta((float) $current, (float) $previous),
        ];
    }

    /**
     * The live queue the admin has to act on, plus how today's inflow compares
     * with yesterday's — a backlog number alone says nothing about direction.
     *
     * @return array<string, mixed>
     */
    private function pendingVerificationStat(Carbon $now): array
    {
        $todayStart = $now->copy()->startOfDay();
        $tomorrowStart = $todayStart->copy()->addDay();
        $yesterdayStart = $todayStart->copy()->subDay();

        // Leading column of index(status, hold_expires_at).
        $backlog = Booking::query()->pendingVerification()->count();

        $row = Booking::query()
            ->whereNotNull('payment_submitted_at')
            ->where('payment_submitted_at', '>=', $yesterdayStart)
            ->where('payment_submitted_at', '<', $tomorrowStart)
            ->selectRaw('COALESCE(SUM(CASE WHEN payment_submitted_at >= ? THEN 1 ELSE 0 END), 0) AS current_total', [$todayStart])
            ->selectRaw('COALESCE(SUM(CASE WHEN payment_submitted_at < ? THEN 1 ELSE 0 END), 0) AS previous_total', [$todayStart])
            ->first();

        $current = (int) ($row->current_total ?? 0);
        $previous = (int) ($row->previous_total ?? 0);

        return [
            'value' => $backlog,
            'display' => number_format($backlog),
            'submitted_today' => $current,
            'previous' => $previous,
            'hint' => number_format($current).' submitted today',
            // More payments arriving is good news, but a growing queue is not
            // something to colour green — the card flags it as neutral-negative.
            'positive_is_good' => false,
            ...$this->percentDelta((float) $current, (float) $previous),
        ];
    }

    /**
     * Month-to-date revenue from confirmed + completed bookings, compared with
     * the identical slice of the previous month rather than its full total.
     *
     * @return array<string, mixed>
     */
    private function revenueMonthStat(Carbon $now): array
    {
        $monthStart = $now->copy()->startOfMonth();
        $previousMonthStart = $monthStart->copy()->subMonthNoOverflow();

        $elapsed = $monthStart->diffInSeconds($now);
        $previousCutoff = $previousMonthStart->copy()->addSeconds((int) $elapsed);
        $previousMonthEnd = $previousMonthStart->copy()->endOfMonth();

        if ($previousCutoff->greaterThan($previousMonthEnd)) {
            $previousCutoff = $previousMonthEnd;
        }

        $row = Booking::query()
            ->revenueBearing()
            ->where('created_at', '>=', $previousMonthStart)
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN amount ELSE 0 END), 0) AS current_total', [$monthStart])
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at < ? THEN amount ELSE 0 END), 0) AS previous_total', [$previousCutoff])
            ->first();

        $current = (float) ($row->current_total ?? 0);
        $previous = (float) ($row->previous_total ?? 0);

        return [
            'value' => round($current, 2),
            'display' => $this->money($current),
            'previous' => round($previous, 2),
            'hint' => 'vs. '.$this->money($previous).' by this point last month',
            ...$this->percentDelta($current, $previous),
        ];
    }

    /**
     * Share of this week's published slots that ended up booked, against the
     * same measure last week. Expressed in percentage points, not a percentage
     * of a percentage — the latter reads impressive and means nothing.
     *
     * @return array<string, mixed>
     */
    private function utilisationWeekStat(Carbon $now): array
    {
        $weekStart = $now->copy()->startOfWeek();
        $weekEnd = $weekStart->copy()->addDays(6);
        $previousWeekStart = $weekStart->copy()->subWeek();

        $row = CourtSlot::query()
            ->whereBetween('slot_date', [$previousWeekStart->toDateString(), $weekEnd->toDateString()])
            ->selectRaw('COALESCE(SUM(CASE WHEN slot_date >= ? THEN 1 ELSE 0 END), 0) AS current_total', [$weekStart->toDateString()])
            ->selectRaw('COALESCE(SUM(CASE WHEN slot_date >= ? AND status = ? THEN 1 ELSE 0 END), 0) AS current_booked', [$weekStart->toDateString(), CourtSlot::STATUS_BOOKED])
            ->selectRaw('COALESCE(SUM(CASE WHEN slot_date < ? THEN 1 ELSE 0 END), 0) AS previous_total', [$weekStart->toDateString()])
            ->selectRaw('COALESCE(SUM(CASE WHEN slot_date < ? AND status = ? THEN 1 ELSE 0 END), 0) AS previous_booked', [$weekStart->toDateString(), CourtSlot::STATUS_BOOKED])
            ->first();

        $currentTotal = (int) ($row->current_total ?? 0);
        $currentBooked = (int) ($row->current_booked ?? 0);
        $previousTotal = (int) ($row->previous_total ?? 0);
        $previousBooked = (int) ($row->previous_booked ?? 0);

        $current = $currentTotal > 0 ? ($currentBooked / $currentTotal) * 100 : 0.0;
        $previous = $previousTotal > 0 ? ($previousBooked / $previousTotal) * 100 : 0.0;

        return [
            'value' => round($current, 1),
            'display' => number_format($current, 1).'%',
            'booked' => $currentBooked,
            'total' => $currentTotal,
            'previous' => round($previous, 1),
            'hint' => $currentTotal > 0
                ? number_format($currentBooked).' of '.number_format($currentTotal).' slots booked'
                : 'No slots published this week',
            ...$this->pointDelta($current, $previous, $currentTotal > 0 && $previousTotal > 0),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Charts                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Daily revenue and booking volume across the window. SQL returns only the
     * days that have rows; the gaps are filled here so the axis is continuous.
     *
     * @return array<string, mixed>
     */
    private function revenueTrend(Carbon $from, Carbon $now, int $days): array
    {
        /** @var Collection<int, \stdClass> $rows */
        $rows = Booking::query()
            ->revenueBearing()
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) AS day')
            ->selectRaw('COALESCE(SUM(amount), 0) AS revenue')
            ->selectRaw('COUNT(*) AS bookings')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $byDay = $rows->keyBy(fn (object $row): string => $this->dayKey($row->day));

        $labels = [];
        $revenue = [];
        $bookings = [];
        $total = 0.0;
        $totalBookings = 0;
        // Long windows get sparser labels so 90 ticks do not collide.
        $labelFormat = $days > 45 ? 'j M' : ($days > 14 ? 'j M' : 'D j');

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $from->copy()->addDays($offset);
            $key = $day->toDateString();
            $row = $byDay->get($key);

            $dayRevenue = round((float) ($row->revenue ?? 0), 2);
            $dayBookings = (int) ($row->bookings ?? 0);

            $labels[] = $day->format($labelFormat);
            $revenue[] = $dayRevenue;
            $bookings[] = $dayBookings;
            $total += $dayRevenue;
            $totalBookings += $dayBookings;
        }

        $best = $revenue === [] ? 0.0 : max($revenue);
        $bestIndex = $best > 0 ? (int) array_search($best, $revenue, true) : null;

        return [
            'labels' => $labels,
            'revenue' => $revenue,
            'bookings' => $bookings,
            'total' => round($total, 2),
            'total_display' => $this->money($total),
            'total_bookings' => $totalBookings,
            'average' => $days > 0 ? round($total / $days, 2) : 0.0,
            'average_display' => $this->money($days > 0 ? $total / $days : 0.0),
            'best_day' => $bestIndex === null ? null : $labels[$bestIndex],
            'best_day_display' => $bestIndex === null ? null : $this->money($best),
            'has_data' => $total > 0 || $totalBookings > 0,
            'as_of' => $now->format('j M Y, g:i A'),
        ];
    }

    /**
     * Booking counts per status for the donut.
     *
     * @return array<string, mixed>
     */
    private function statusMix(Carbon $from): array
    {
        /** @var Collection<string, int> $counts */
        $counts = Booking::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('status')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $labels = [];
        $data = [];
        $statuses = [];
        $total = 0;

        // Iterate the canonical enum so the donut segments keep a stable order
        // and colour whichever statuses happen to exist in the window.
        foreach (Booking::STATUSES as $status) {
            $count = (int) ($counts[$status] ?? 0);

            if ($count === 0) {
                continue;
            }

            $labels[] = $this->humanise($status);
            $data[] = $count;
            $statuses[] = $status;
            $total += $count;
        }

        return [
            'labels' => $labels,
            'data' => $data,
            'statuses' => $statuses,
            'total' => $total,
            'has_data' => $total > 0,
        ];
    }

    /**
     * Booking volume and revenue per court. Two queries — an aggregate and a
     * name lookup — never one per row.
     *
     * @return array<string, mixed>
     */
    private function bookingsPerCourt(Carbon $from): array
    {
        // Cross-court attribution: a combined booking that spans several courts
        // credits its whole immutable amount to its PRIMARY court (grouped on
        // court_id here), so the chart's total revenue stays exact and only the
        // per-court split rounds toward the primary court. It is deliberately not
        // apportioned per slot: bookings.amount is the frozen charged sum, whereas
        // court_slots.price is mutable and splitting by it would retroactively
        // rewrite history. Faithful per-court money needs a per-slot price frozen
        // at reserve time — a schema change that is out of scope here.
        /** @var Collection<int, \stdClass> $rows */
        $rows = Booking::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('court_id')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN status IN (?, ?) THEN amount ELSE 0 END), 0) AS revenue', [
                Booking::STATUS_CONFIRMED,
                Booking::STATUS_COMPLETED,
            ])
            ->groupBy('court_id')
            ->orderByDesc('total')
            ->limit(self::COURT_LIMIT)
            ->get();

        if ($rows->isEmpty()) {
            return [
                'labels' => [],
                'data' => [],
                'revenue' => [],
                'total' => 0,
                'has_data' => false,
            ];
        }

        /** @var Collection<int, string> $names */
        $names = Court::withTrashed()
            ->whereIn('id', $rows->pluck('court_id')->filter()->all())
            ->pluck('name', 'id');

        $labels = [];
        $data = [];
        $revenue = [];
        $total = 0;

        foreach ($rows as $row) {
            $courtId = (int) $row->court_id;
            $count = (int) $row->total;

            $labels[] = (string) ($names[$courtId] ?? 'Court #'.$courtId);
            $data[] = $count;
            $revenue[] = round((float) $row->revenue, 2);
            $total += $count;
        }

        return [
            'labels' => $labels,
            'data' => $data,
            'revenue' => $revenue,
            'total' => $total,
            'has_data' => true,
        ];
    }

    /**
     * Demand by hour of day, taken from the booked slot's start time rather
     * than when the booking was made — the operator cares which hours sell.
     *
     * This measures COURT UTILISATION (hours of court time occupied), not
     * booking counts or revenue: it deliberately joins on
     * `court_slots.held_booking_id = bookings.id` rather than the usual
     * `bookings.court_slot_id`, so a single multi-slot booking correctly
     * contributes one row to this histogram for EVERY hour of court time it
     * occupies (e.g. a 3-slot booking fans out into 3 rows here — one per
     * actual hour played), not just its one primary/earliest slot.
     *
     * This is the ONE deliberate exception in this codebase to the rule that
     * joining bookings to court_slots via held_booking_id must never feed a
     * SUM(bookings.amount) or COUNT(*)-of-bookings — everywhere else (revenue
     * totals, booking counts, the reports in ReportService) that fan-out
     * would silently multiply money/booking totals by the number of slots and
     * must stay on the unchanged 1:1 `bookings.court_slot_id` column instead.
     * Here the fan-out is the correct behaviour, not a bug, because the unit
     * being counted is "an hour of court time", not "a booking" or "a peso".
     *
     * @return array<string, mixed>
     */
    private function peakHours(Carbon $from): array
    {
        /** @var Collection<int, \stdClass> $rows */
        $rows = DB::table('bookings')
            ->join('court_slots', 'court_slots.held_booking_id', '=', 'bookings.id')
            ->whereNull('bookings.deleted_at')
            ->where('bookings.created_at', '>=', $from)
            ->selectRaw('HOUR(court_slots.start_time) AS hour_of_day')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('hour_of_day')
            ->get();

        $buckets = array_fill(0, 24, 0);

        foreach ($rows as $row) {
            $hour = (int) $row->hour_of_day;

            if ($hour >= 0 && $hour <= 23) {
                $buckets[$hour] = (int) $row->total;
            }
        }

        $peak = max($buckets);
        $total = array_sum($buckets);

        $hours = [];

        foreach ($buckets as $hour => $count) {
            $hours[] = [
                'hour' => $hour,
                'label' => $this->hourLabel($hour),
                'short' => $this->hourShortLabel($hour),
                'count' => $count,
                // 0..1, drives the heat cell opacity on the client.
                'intensity' => $peak > 0 ? round($count / $peak, 4) : 0.0,
            ];
        }

        $busiest = null;

        if ($peak > 0) {
            $busiestHour = (int) array_search($peak, $buckets, true);
            $busiest = [
                'hour' => $busiestHour,
                'label' => $this->hourLabel($busiestHour),
                'count' => $peak,
            ];
        }

        return [
            'hours' => $hours,
            'peak' => $peak,
            'total' => $total,
            'busiest' => $busiest,
            'has_data' => $total > 0,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Feeds                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @return list<array<string, mixed>>
     */
    private function recentActivity(): array
    {
        return AuditTrail::query()
            ->with(['user:id,name,username,avatar_path'])
            ->latestFirst()
            ->limit(self::ACTIVITY_LIMIT)
            ->get([
                'id',
                'user_id',
                'user_name',
                'role_name',
                'module',
                'action',
                'description',
                'auditable_type',
                'auditable_id',
                'ip_address',
                'created_at',
            ])
            ->map(fn (AuditTrail $log): array => [
                'id' => $log->getKey(),
                // Snapshot first: the user row may be long gone.
                'user_name' => $log->user_name ?: ($log->user?->name ?? 'System'),
                'user_initials' => $this->initials($log->user_name ?: ($log->user?->name ?? 'System')),
                'role_name' => $log->role_name,
                'module' => $log->module,
                'action' => $log->action,
                'action_label' => $this->humanise((string) $log->action),
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
                'created_at_display' => $log->created_at?->format('j M, g:i A'),
                'created_at_human' => $log->created_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Confirmed bookings whose slot has not started yet, soonest first.
     *
     * @return list<array<string, mixed>>
     */
    private function upcomingBookings(Carbon $now): array
    {
        $today = $now->toDateString();
        $time = $now->format('H:i:s');

        // Per-booking "what's coming up" feed — one row per booking, so this
        // join stays on the unchanged 1:1 bookings.court_slot_id column
        // (never held_booking_id, which would fan one booking out into N rows
        // here too).
        return Booking::query()
            ->select('bookings.*')
            ->join('court_slots', 'court_slots.id', '=', 'bookings.court_slot_id')
            ->where('bookings.status', Booking::STATUS_CONFIRMED)
            ->where(function (Builder $query) use ($today, $time): void {
                $query->where('court_slots.slot_date', '>', $today)
                    ->orWhere(function (Builder $inner) use ($today, $time): void {
                        $inner->where('court_slots.slot_date', '=', $today)
                            ->where('court_slots.start_time', '>=', $time);
                    });
            })
            ->with([
                'court:id,name,code',
                'slot:id,court_id,slot_date,start_time,end_time',
                // Full slot set, narrow columns, purely so the feed can show a
                // "+N more" hint for multi-slot bookings. A plain eager-load
                // (not a join), so it cannot affect this query's row count.
                'slots:id,held_booking_id,court_id,slot_date,start_time,end_time',
            ])
            ->orderBy('court_slots.slot_date')
            ->orderBy('court_slots.start_time')
            ->limit(self::UPCOMING_LIMIT)
            ->get()
            ->map(function (Booking $booking) use ($now): array {
                $slot = $booking->slot;
                $startsAt = $slot?->startsAt();
                $extraSlots = max(0, $booking->slots->count() - 1);

                return [
                    'id' => $booking->getKey(),
                    'code' => $booking->code,
                    'customer_name' => $booking->customer_name,
                    'customer_initials' => $this->initials((string) $booking->customer_name),
                    'customer_phone' => $booking->customer_phone,
                    'court_name' => $booking->court?->name ?? 'Court',
                    'court_code' => $booking->court?->code,
                    'status' => $booking->status,
                    'amount' => round((float) $booking->amount, 2),
                    'amount_display' => $this->money((float) $booking->amount),
                    'date_display' => $startsAt?->format('D, j M'),
                    'time_range' => $slot?->time_range,
                    // Slots beyond the primary one shown above, if any — lets
                    // the UI show a "+N more" hint without a second query.
                    'more_slots' => $extraSlots,
                    'starts_in' => $startsAt === null ? null : $startsAt->diffForHumans([
                        'syntax' => CarbonInterface::DIFF_ABSOLUTE,
                        'parts' => 2,
                        'short' => true,
                    ]),
                    'is_today' => $startsAt !== null && $startsAt->isSameDay($now),
                ];
            })
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /* Formatting helpers                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Relative change as a signed percentage, with the sane behaviours around
     * a zero baseline that a naive division would get wrong.
     *
     * @return array{delta: string|null, trend: string, change: float|null}
     */
    private function percentDelta(float $current, float $previous): array
    {
        if (abs($previous) < 0.005) {
            if (abs($current) < 0.005) {
                return ['delta' => 'No change', 'trend' => 'flat', 'change' => 0.0];
            }

            return ['delta' => 'New', 'trend' => 'up', 'change' => null];
        }

        $change = round((($current - $previous) / abs($previous)) * 100, 1);

        return [
            'delta' => ($change > 0 ? '+' : '').number_format($change, 1).'%',
            'trend' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
            'change' => $change,
        ];
    }

    /**
     * Difference between two percentages, in percentage points.
     *
     * @return array{delta: string|null, trend: string, change: float|null}
     */
    private function pointDelta(float $current, float $previous, bool $comparable): array
    {
        if (! $comparable) {
            return ['delta' => null, 'trend' => 'flat', 'change' => null];
        }

        $change = round($current - $previous, 1);

        return [
            'delta' => ($change > 0 ? '+' : '').number_format($change, 1).' pts',
            'trend' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
            'change' => $change,
        ];
    }

    private function money(float $amount): string
    {
        return self::PESO.number_format($amount, 2);
    }

    private function humanise(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return '?';
        }

        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[count($parts) - 1], 0, 1));
    }

    private function hourLabel(int $hour): string
    {
        $suffix = $hour < 12 ? 'AM' : 'PM';
        $display = $hour % 12;

        return ($display === 0 ? 12 : $display).' '.$suffix;
    }

    private function hourShortLabel(int $hour): string
    {
        $display = $hour % 12;

        return (string) ($display === 0 ? 12 : $display);
    }

    /**
     * MySQL hands DATE() back as `Y-m-d`; other drivers can return a full
     * timestamp, so normalise before keying.
     */
    private function dayKey(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = (string) $value;

        return str_contains($value, ' ') ? substr($value, 0, 10) : $value;
    }
}
