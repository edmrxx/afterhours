<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reporting read-model for the bookings and revenue screens, and for their CSV
 * exports.
 *
 * Every number on those screens is produced by a GROUP BY / aggregate query —
 * nothing is ever counted in PHP by hydrating a result set. The dataset this
 * has to survive is the bookings table after a year of trading, so the rules
 * are:
 *
 *  - aggregates return scalars or a handful of grouped rows, never models;
 *  - the only unbounded read (the CSV export) is a keyset-paged lazy cursor;
 *  - the requested date range is clamped, so no filter combination can ask the
 *    database for "everything".
 *
 * Two date bases are supported because "revenue in July" is genuinely
 * ambiguous for a booking system:
 *
 *  - `booked` — when the money came in (bookings.created_at). The default.
 *  - `played` — when the court is used (court_slots.slot_date).
 */
class ReportService
{
    /** Report against the date the booking was taken. */
    public const BASIS_BOOKED = 'booked';

    /** Report against the date the slot is played. */
    public const BASIS_PLAYED = 'played';

    /** @var list<string> */
    public const BASES = [self::BASIS_BOOKED, self::BASIS_PLAYED];

    /** @var list<string> */
    public const GRANULARITIES = ['day', 'week', 'month'];

    /**
     * Only these statuses represent money actually taken — ARCHITECTURE.md §4.
     *
     * @var list<string>
     */
    public const REVENUE_STATUSES = [Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED];

    /** Default window when the user has not chosen one: the last 30 days. */
    private const DEFAULT_RANGE_DAYS = 29;

    /**
     * Hard ceiling on the requested window. Two years of daily buckets is
     * already more points than a chart can render usefully, and it bounds the
     * worst-case aggregate scan.
     */
    private const MAX_RANGE_DAYS = 730;

    /**
     * Columns the detail table may be ordered by. Anything else is ignored —
     * `sort` arrives straight from the query string.
     *
     * @var array<string, string>
     */
    private const SORTABLE = [
        'created_at' => 'bookings.created_at',
        'code' => 'bookings.code',
        'customer_name' => 'bookings.customer_name',
        'amount' => 'bookings.amount',
        'status' => 'bookings.status',
    ];

    /* --------------------------------------------------------------------- */
    /* Filters                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Turn raw query-string input into a filter set that is safe to interpolate
     * into SQL and guaranteed to describe a sane, bounded window.
     *
     * @param  array<string, mixed>  $input
     * @return array{date_from: string, date_to: string, court_id: int|null, status: string|null, basis: string, granularity: string}
     */
    public function normaliseFilters(array $input): array
    {
        $to = $this->parseDate($input['date_to'] ?? null) ?? Carbon::today();
        $from = $this->parseDate($input['date_from'] ?? null) ?? $to->copy()->subDays(self::DEFAULT_RANGE_DAYS);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        // Clamp rather than reject: a nonsense range should still render a page.
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = $to->copy()->subDays(self::MAX_RANGE_DAYS);
        }

        $courtId = (int) ($input['court_id'] ?? 0);
        $status = is_string($input['status'] ?? null) ? $input['status'] : null;
        $basis = is_string($input['basis'] ?? null) ? $input['basis'] : self::BASIS_BOOKED;
        $granularity = is_string($input['granularity'] ?? null) ? $input['granularity'] : null;

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'court_id' => $courtId > 0 ? $courtId : null,
            'status' => in_array($status, Booking::STATUSES, true) ? $status : null,
            'basis' => in_array($basis, self::BASES, true) ? $basis : self::BASIS_BOOKED,
            'granularity' => in_array($granularity, self::GRANULARITIES, true)
                ? $granularity
                : $this->suggestGranularity($from, $to),
        ];
    }

    /**
     * Courts for the filter dropdown. Soft-deleted courts stay out of the
     * picker but remain visible in historical aggregates.
     *
     * @return Collection<int, array{value: int, label: string}>
     */
    public function courtOptions(): Collection
    {
        return Court::query()
            ->select(['id', 'name', 'sort_order'])
            ->ordered()
            ->get()
            ->map(fn (Court $court): array => [
                'value' => (int) $court->getKey(),
                'label' => (string) $court->name,
            ])
            ->values();
    }

    /**
     * "21 Jun – 20 Jul 2026" — used in headings and export filenames.
     *
     * @param  array<string, mixed>  $filters
     */
    public function rangeLabel(array $filters): string
    {
        $from = Carbon::parse((string) $filters['date_from']);
        $to = Carbon::parse((string) $filters['date_to']);

        if ($from->isSameYear($to)) {
            return $from->format('j M').' – '.$to->format('j M Y');
        }

        return $from->format('j M Y').' – '.$to->format('j M Y');
    }

    /* --------------------------------------------------------------------- */
    /* Bookings report                                                        */
    /* --------------------------------------------------------------------- */

    /**
     * Headline numbers for the bookings report — one round trip, all aggregates.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, float|int>
     */
    public function bookingsSummary(array $filters): array
    {
        // Money/row-count aggregate: stays 1:1 on the primary slot (baseQuery's
        // played-basis join is on bookings.court_slot_id). Never join this
        // against court_slots.held_booking_id — that column can match several
        // slot rows per booking now and would fan out and overcount SUM/COUNT.
        $row = $this->baseQuery($filters)
            ->selectRaw('COUNT(*) as total_bookings')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN bookings.status IN (?, ?) THEN bookings.amount ELSE 0 END), 0) as revenue',
                self::REVENUE_STATUSES,
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN bookings.status IN (?, ?) THEN 1 ELSE 0 END), 0) as revenue_bookings',
                self::REVENUE_STATUSES,
            )
            ->selectRaw('COALESCE(SUM(CASE WHEN bookings.status = ? THEN 1 ELSE 0 END), 0) as confirmed', [Booking::STATUS_CONFIRMED])
            ->selectRaw('COALESCE(SUM(CASE WHEN bookings.status = ? THEN 1 ELSE 0 END), 0) as completed', [Booking::STATUS_COMPLETED])
            ->selectRaw('COALESCE(SUM(CASE WHEN bookings.status = ? THEN 1 ELSE 0 END), 0) as rejected', [Booking::STATUS_REJECTED])
            ->selectRaw('COALESCE(SUM(CASE WHEN bookings.status = ? THEN 1 ELSE 0 END), 0) as cancelled', [Booking::STATUS_CANCELLED])
            ->selectRaw('COALESCE(SUM(CASE WHEN bookings.status = ? THEN 1 ELSE 0 END), 0) as expired', [Booking::STATUS_EXPIRED])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN bookings.status IN (?, ?) THEN 1 ELSE 0 END), 0) as pending',
                Booking::HOLDING_STATUSES,
            )
            ->toBase()
            ->first();

        $total = (int) ($row->total_bookings ?? 0);
        $revenueBookings = (int) ($row->revenue_bookings ?? 0);
        $revenue = (float) ($row->revenue ?? 0);
        $settled = (int) ($row->confirmed ?? 0) + (int) ($row->completed ?? 0);

        return [
            'total_bookings' => $total,
            'revenue' => round($revenue, 2),
            'revenue_bookings' => $revenueBookings,
            'average_value' => $revenueBookings > 0 ? round($revenue / $revenueBookings, 2) : 0.0,
            'confirmed' => (int) ($row->confirmed ?? 0),
            'completed' => (int) ($row->completed ?? 0),
            'rejected' => (int) ($row->rejected ?? 0),
            'cancelled' => (int) ($row->cancelled ?? 0),
            'expired' => (int) ($row->expired ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            // Share of bookings that turned into a played, paid game.
            'conversion_rate' => $total > 0 ? round(($settled / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * Booking counts per status, in the canonical state-machine order with
     * zero-filled gaps so the donut legend never reorders between loads.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{status: string, label: string, total: int, amount: float}>
     */
    public function bookingsByStatus(array $filters): array
    {
        // Money/row-count aggregate: stays 1:1 on the primary slot. Never join
        // this against court_slots.held_booking_id (multi-slot fan-out would
        // overcount both the COUNT and the SUM below).
        $rows = $this->baseQuery($filters)
            ->selectRaw('bookings.status as status')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(bookings.amount), 0) as amount')
            ->groupBy('bookings.status')
            ->toBase()
            ->get()
            ->keyBy('status');

        $out = [];

        foreach (Booking::STATUSES as $status) {
            $row = $rows->get($status);

            $out[] = [
                'status' => $status,
                'label' => Str::headline($status),
                'total' => (int) ($row->total ?? 0),
                'amount' => round((float) ($row->amount ?? 0), 2),
            ];
        }

        return $out;
    }

    /**
     * Bookings and revenue bucketed over time, gap-filled across the whole range.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{period: string, label: string, bookings: int, revenue: float}>
     */
    public function bookingsByPeriod(array $filters): array
    {
        // Money/row-count aggregate: stays 1:1 on the primary slot. Never join
        // this against court_slots.held_booking_id (multi-slot fan-out would
        // overcount both the COUNT and the SUM below).
        $expression = $this->periodExpression($filters);

        $rows = $this->baseQuery($filters)
            ->selectRaw("{$expression} as period")
            ->selectRaw('COUNT(*) as bookings')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN bookings.status IN (?, ?) THEN bookings.amount ELSE 0 END), 0) as revenue',
                self::REVENUE_STATUSES,
            )
            ->groupByRaw($expression)
            ->orderByRaw($expression)
            ->toBase()
            ->get()
            ->keyBy(fn (object $row): string => $this->periodKey($row->period));

        return $this->fillBuckets($filters, $rows, static fn (?object $row): array => [
            'bookings' => (int) ($row->bookings ?? 0),
            'revenue' => round((float) ($row->revenue ?? 0), 2),
        ]);
    }

    /**
     * Bookings and revenue split by court, busiest first.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{court_id: int, court: string, bookings: int, revenue: float, share: float}>
     */
    public function bookingsByCourt(array $filters): array
    {
        // Money/row-count aggregate: joined only to courts (bookings.court_id),
        // and stays 1:1 on the primary slot for the played basis. Never join
        // this against court_slots.held_booking_id (multi-slot fan-out would
        // overcount both the COUNT and the SUM below).
        //
        // Cross-court attribution: a combined booking that spans several courts
        // credits its whole immutable amount to its PRIMARY court
        // (bookings.court_id), so the grand total stays exact and only the
        // per-court split rounds toward the primary court. It is deliberately not
        // apportioned per slot: bookings.amount is the frozen charged sum, whereas
        // court_slots.price is mutable and splitting by it would retroactively
        // rewrite history. Faithful per-court money needs a per-slot price frozen
        // at reserve time — a schema change that is out of scope here.
        $rows = $this->baseQuery($filters)
            ->join('courts', 'courts.id', '=', 'bookings.court_id')
            ->selectRaw('courts.id as court_id')
            ->selectRaw('courts.name as court_name')
            ->selectRaw('COUNT(*) as bookings')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN bookings.status IN (?, ?) THEN bookings.amount ELSE 0 END), 0) as revenue',
                self::REVENUE_STATUSES,
            )
            ->groupBy('courts.id', 'courts.name')
            ->orderByDesc('revenue')
            ->orderBy('courts.name')
            ->toBase()
            ->get();

        $totalRevenue = (float) $rows->sum(static fn (object $row): float => (float) $row->revenue);

        return $rows->map(static fn (object $row): array => [
            'court_id' => (int) $row->court_id,
            'court' => (string) $row->court_name,
            'bookings' => (int) $row->bookings,
            'revenue' => round((float) $row->revenue, 2),
            'share' => $totalRevenue > 0.0 ? round(((float) $row->revenue / $totalRevenue) * 100, 1) : 0.0,
        ])->all();
    }

    /**
     * The paginated detail table under the bookings report.
     *
     * @param  array<string, mixed>  $filters
     * @param  array{search?: string|null, sort?: string|null, direction?: string|null, per_page?: int}  $table
     * @return LengthAwarePaginator<int, Booking>
     */
    public function bookingsDetail(array $filters, array $table = []): LengthAwarePaginator
    {
        $sort = self::SORTABLE[$table['sort'] ?? ''] ?? self::SORTABLE['created_at'];
        $direction = ($table['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $perPage = max(10, min(100, (int) ($table['per_page'] ?? 25)));

        return $this->baseQuery($filters)
            ->select('bookings.*')
            ->with([
                'court:id,name,code',
                'slot:id,court_id,slot_date,start_time,end_time',
            ])
            ->when(
                filled($table['search'] ?? null),
                fn (Builder $query) => $this->applySearch($query, (string) $table['search']),
            )
            ->orderBy($sort, $direction)
            // Deterministic tie-break: without it, equal timestamps can repeat
            // or drop rows across page boundaries.
            ->orderByDesc('bookings.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /* --------------------------------------------------------------------- */
    /* Revenue report                                                         */
    /* --------------------------------------------------------------------- */

    /**
     * Revenue headline, including the change against the immediately preceding
     * window of the same length.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<array{period: string, label: string, bookings: int, revenue: float}>  $periods
     * @return array<string, mixed>
     */
    public function revenueSummary(array $filters, array $periods = []): array
    {
        $current = $this->revenueTotals($filters);
        $previous = $this->revenueTotals($this->previousRange($filters));

        $change = null;

        if ($previous['revenue'] > 0.0) {
            $change = round((($current['revenue'] - $previous['revenue']) / $previous['revenue']) * 100, 1);
        } elseif ($current['revenue'] > 0.0) {
            $change = 100.0;
        }

        $peak = null;

        foreach ($periods as $bucket) {
            if ($peak === null || $bucket['revenue'] > $peak['revenue']) {
                $peak = $bucket;
            }
        }

        return [
            'revenue' => $current['revenue'],
            'bookings' => $current['bookings'],
            'average_value' => $current['bookings'] > 0
                ? round($current['revenue'] / $current['bookings'], 2)
                : 0.0,
            'courts' => $current['courts'],
            'previous_revenue' => $previous['revenue'],
            'change_percent' => $change,
            'peak_label' => $peak !== null && $peak['revenue'] > 0.0 ? $peak['label'] : null,
            'peak_revenue' => $peak !== null ? $peak['revenue'] : 0.0,
        ];
    }

    /**
     * Revenue bucketed by day, week or month, gap-filled across the range so a
     * quiet Tuesday reads as zero rather than vanishing off the axis.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{period: string, label: string, bookings: int, revenue: float}>
     */
    public function revenueByPeriod(array $filters): array
    {
        // Money/row-count aggregate: stays 1:1 on the primary slot. Never join
        // this against court_slots.held_booking_id (multi-slot fan-out would
        // overcount both the COUNT and the SUM(amount) below).
        $expression = $this->periodExpression($filters);

        $rows = $this->baseQuery($filters)
            ->whereIn('bookings.status', self::REVENUE_STATUSES)
            ->selectRaw("{$expression} as period")
            ->selectRaw('COUNT(*) as bookings')
            ->selectRaw('COALESCE(SUM(bookings.amount), 0) as revenue')
            ->groupByRaw($expression)
            ->orderByRaw($expression)
            ->toBase()
            ->get()
            ->keyBy(fn (object $row): string => $this->periodKey($row->period));

        return $this->fillBuckets($filters, $rows, static fn (?object $row): array => [
            'bookings' => (int) ($row->bookings ?? 0),
            'revenue' => round((float) ($row->revenue ?? 0), 2),
        ]);
    }

    /**
     * Revenue split by court, highest earner first.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{court_id: int, court: string, bookings: int, revenue: float, average: float, share: float}>
     */
    public function revenueByCourt(array $filters): array
    {
        // Money/row-count aggregate: joined only to courts (bookings.court_id),
        // and stays 1:1 on the primary slot for the played basis. Never join
        // this against court_slots.held_booking_id (multi-slot fan-out would
        // overcount both the COUNT and the SUM(amount) below).
        //
        // Cross-court attribution: a combined booking that spans several courts
        // credits its whole immutable amount to its PRIMARY court
        // (bookings.court_id), so total revenue stays exact and only the per-court
        // split (and the `share`/`average` derived from it) rounds toward the
        // primary court. It is deliberately not apportioned per slot:
        // bookings.amount is the frozen charged sum, whereas court_slots.price is
        // mutable and splitting by it would retroactively rewrite history.
        // Faithful per-court money needs a per-slot price frozen at reserve time —
        // a schema change that is out of scope here.
        $rows = $this->baseQuery($filters)
            ->join('courts', 'courts.id', '=', 'bookings.court_id')
            ->whereIn('bookings.status', self::REVENUE_STATUSES)
            ->selectRaw('courts.id as court_id')
            ->selectRaw('courts.name as court_name')
            ->selectRaw('COUNT(*) as bookings')
            ->selectRaw('COALESCE(SUM(bookings.amount), 0) as revenue')
            ->groupBy('courts.id', 'courts.name')
            ->orderByDesc('revenue')
            ->orderBy('courts.name')
            ->toBase()
            ->get();

        $totalRevenue = (float) $rows->sum(static fn (object $row): float => (float) $row->revenue);

        return $rows->map(static function (object $row) use ($totalRevenue): array {
            $revenue = round((float) $row->revenue, 2);
            $bookings = (int) $row->bookings;

            return [
                'court_id' => (int) $row->court_id,
                'court' => (string) $row->court_name,
                'bookings' => $bookings,
                'revenue' => $revenue,
                'average' => $bookings > 0 ? round($revenue / $bookings, 2) : 0.0,
                'share' => $totalRevenue > 0.0 ? round(($revenue / $totalRevenue) * 100, 1) : 0.0,
            ];
        })->all();
    }

    /* --------------------------------------------------------------------- */
    /* Export sources                                                         */
    /* --------------------------------------------------------------------- */

    /**
     * Every booking matching the filters, streamed with a keyset cursor.
     *
     * `lazyById` keeps exactly one chunk in memory at a time and pages on the
     * primary key rather than OFFSET, so the last page costs the same as the
     * first. Relations are eager-loaded per chunk — no N+1 across the stream.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, Booking>
     */
    public function bookingsCursor(array $filters, int $chunkSize = 500): LazyCollection
    {
        return $this->baseQuery($filters)
            ->select('bookings.*')
            ->with([
                'court:id,name,code',
                'slot:id,court_id,slot_date,start_time,end_time',
                // Full slot set (narrow columns) so the export can list any
                // slots beyond the primary one without an N+1 per row. This is
                // a plain eager-load via Eloquent's relation, not a join — it
                // cannot affect any SUM/COUNT computed by baseQuery() above.
                'slots:id,held_booking_id,court_id,slot_date,start_time,end_time',
                'confirmedBy:id,name',
                'rejectedBy:id,name',
            ])
            ->orderBy('bookings.id')
            ->lazyById($chunkSize, 'bookings.id', 'id');
    }

    /**
     * The revenue export body: one row per (period, court), streamed straight
     * off the driver cursor so the aggregate is never materialised in full.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, object>
     */
    public function revenueBreakdownCursor(array $filters): LazyCollection
    {
        $expression = $this->periodExpression($filters);

        return $this->baseQuery($filters)
            ->join('courts', 'courts.id', '=', 'bookings.court_id')
            ->whereIn('bookings.status', self::REVENUE_STATUSES)
            ->selectRaw("{$expression} as period")
            ->selectRaw('courts.name as court_name')
            ->selectRaw('COUNT(*) as bookings')
            ->selectRaw('COALESCE(SUM(bookings.amount), 0) as revenue')
            ->groupByRaw("{$expression}, courts.id, courts.name")
            ->orderByRaw($expression)
            ->orderBy('courts.name')
            ->toBase()
            ->cursor();
    }

    /**
     * Human label for a bucket key produced by the export cursor.
     *
     * @param  array<string, mixed>  $filters
     */
    public function labelForPeriod(mixed $period, array $filters): string
    {
        $key = $this->periodKey($period);

        if ($key === '') {
            return '—';
        }

        try {
            return $this->bucketLabel(Carbon::parse($key), (string) ($filters['granularity'] ?? 'day'));
        } catch (Throwable) {
            return $key;
        }
    }

    /**
     * Formatted play window for one booking row, without touching the database
     * again. Returns an em dash when the slot has been hard-deleted.
     */
    public function slotLabel(?CourtSlot $slot): string
    {
        if (! $slot instanceof CourtSlot) {
            return '—';
        }

        try {
            return $slot->startsAt()->format('Y-m-d g:i A').' – '.$slot->endsAt()->format('g:i A');
        } catch (Throwable) {
            return '—';
        }
    }

    /* --------------------------------------------------------------------- */
    /* Query construction                                                     */
    /* --------------------------------------------------------------------- */

    /**
     * The filtered booking set every aggregate above builds on.
     *
     * Columns are always table-qualified: `bookings` and `court_slots` both
     * carry a `status` column, so the `played` basis join would otherwise raise
     * "column 'status' in where clause is ambiguous".
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Booking>
     */
    private function baseQuery(array $filters): Builder
    {
        $query = Booking::query();

        $from = Carbon::parse((string) $filters['date_from'])->startOfDay();
        $to = Carbon::parse((string) $filters['date_to'])->endOfDay();

        if (($filters['basis'] ?? self::BASIS_BOOKED) === self::BASIS_PLAYED) {
            $query
                ->join('court_slots', 'court_slots.id', '=', 'bookings.court_slot_id')
                ->whereBetween('court_slots.slot_date', [$from->toDateString(), $to->toDateString()]);
        } else {
            $query->whereBetween('bookings.created_at', [$from, $to]);
        }

        return $query
            ->when(
                $filters['court_id'] ?? null,
                fn (Builder $q, int $courtId) => $q->where('bookings.court_id', $courtId),
            )
            ->when(
                $filters['status'] ?? null,
                fn (Builder $q, string $status) => $q->where('bookings.status', $status),
            );
    }

    /**
     * Qualified free-text search — Booking::scopeSearch cannot be reused here
     * because its column names are bare and would collide under the join.
     *
     * @param  Builder<Booking>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        $query->where(function (Builder $inner) use ($like): void {
            $inner->where('bookings.code', 'like', $like)
                ->orWhere('bookings.customer_name', 'like', $like)
                ->orWhere('bookings.customer_phone', 'like', $like)
                ->orWhere('bookings.customer_email', 'like', $like)
                ->orWhere('bookings.payment_reference', 'like', $like);
        });
    }

    /**
     * The SQL expression that collapses a row onto its bucket start date.
     *
     * Both the column and the granularity come from a fixed whitelist, so this
     * string is never influenced by user input.
     *
     * @param  array<string, mixed>  $filters
     */
    private function periodExpression(array $filters): string
    {
        $column = ($filters['basis'] ?? self::BASIS_BOOKED) === self::BASIS_PLAYED
            ? 'court_slots.slot_date'
            : 'bookings.created_at';

        return match ($filters['granularity'] ?? 'day') {
            // WEEKDAY() is 0 on Monday, so subtracting it snaps to week start.
            'week' => "DATE(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY))",
            'month' => "DATE_FORMAT({$column}, '%Y-%m-01')",
            default => "DATE({$column})",
        };
    }

    /**
     * Normalise whatever the driver hands back for a bucket (a date string, a
     * full datetime) to a plain Y-m-d key.
     */
    private function periodKey(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return (string) $value;
        }
    }

    /**
     * Walk every bucket in the range and merge in whatever the aggregate found,
     * so charts get a continuous series instead of a sparse one.
     *
     * @param  array<string, mixed>  $filters
     * @param  Collection<string, object>  $rows
     * @param  callable(object|null): array<string, mixed>  $map
     * @return list<array<string, mixed>>
     */
    private function fillBuckets(array $filters, Collection $rows, callable $map): array
    {
        $granularity = (string) ($filters['granularity'] ?? 'day');
        $cursor = $this->bucketStart(Carbon::parse((string) $filters['date_from']), $granularity);
        $end = $this->bucketStart(Carbon::parse((string) $filters['date_to']), $granularity);

        $out = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();

            $out[] = [
                'period' => $key,
                'label' => $this->bucketLabel($cursor, $granularity),
                ...$map($rows->get($key)),
            ];

            $cursor = match ($granularity) {
                'week' => $cursor->copy()->addWeek(),
                'month' => $cursor->copy()->addMonthNoOverflow(),
                default => $cursor->copy()->addDay(),
            };
        }

        return $out;
    }

    private function bucketStart(Carbon $date, string $granularity): Carbon
    {
        return match ($granularity) {
            'week' => $date->copy()->startOfWeek(Carbon::MONDAY),
            'month' => $date->copy()->startOfMonth(),
            default => $date->copy()->startOfDay(),
        };
    }

    private function bucketLabel(Carbon $date, string $granularity): string
    {
        return match ($granularity) {
            'week' => 'wk '.$date->format('j M'),
            'month' => $date->format('M Y'),
            default => $date->format('j M'),
        };
    }

    /**
     * Day buckets stop being readable long before the range limit does, so pick
     * a sensible default the user can still override.
     */
    private function suggestGranularity(Carbon $from, Carbon $to): string
    {
        $days = (int) $from->diffInDays($to);

        return match (true) {
            $days > 365 => 'month',
            $days > 92 => 'week',
            default => 'day',
        };
    }

    /**
     * Revenue-only totals for one window.
     *
     * @param  array<string, mixed>  $filters
     * @return array{revenue: float, bookings: int, courts: int}
     */
    private function revenueTotals(array $filters): array
    {
        // Money/row-count aggregate: stays 1:1 on the primary slot. Never join
        // this against court_slots.held_booking_id (multi-slot fan-out would
        // overcount both the COUNT and the SUM(amount) below).
        $row = $this->baseQuery($filters)
            ->whereIn('bookings.status', self::REVENUE_STATUSES)
            ->selectRaw('COALESCE(SUM(bookings.amount), 0) as revenue')
            ->selectRaw('COUNT(*) as bookings')
            ->selectRaw('COUNT(DISTINCT bookings.court_id) as courts')
            ->toBase()
            ->first();

        return [
            'revenue' => round((float) ($row->revenue ?? 0), 2),
            'bookings' => (int) ($row->bookings ?? 0),
            'courts' => (int) ($row->courts ?? 0),
        ];
    }

    /**
     * The window of the same length ending the day before this one starts.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function previousRange(array $filters): array
    {
        $from = Carbon::parse((string) $filters['date_from']);
        $to = Carbon::parse((string) $filters['date_to']);
        $length = (int) $from->diffInDays($to);

        $previousTo = $from->copy()->subDay();

        return [
            ...$filters,
            'date_from' => $previousTo->copy()->subDays($length)->toDateString(),
            'date_to' => $previousTo->toDateString(),
        ];
    }

    /**
     * Parse a user-supplied date, returning null for anything unusable rather
     * than letting Carbon throw out of a report screen.
     */
    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
