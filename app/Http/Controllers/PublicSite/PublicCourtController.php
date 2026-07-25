<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\Setting;
use App\Services\PricingService;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use stdClass;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop window: a single spreadsheet-style widget — every active court is
 * a column, every distinct time-of-day is a row, one date at a time.
 *
 * Both entry points are read-only, unauthenticated and hit on every marketing
 * click, so they are written for the cheapest possible query plan:
 *
 *  - the date navigator costs one grouped aggregate query across every active
 *    court, whatever the horizon;
 *  - the grid for the selected day costs exactly one query (whereIn court_id,
 *    whereDate slot_date) — the row/column pivot happens in PHP, never one
 *    query per court and never one query per row.
 *
 * index() and show() both render 'PublicSite/CourtsIndex' — show() exists only
 * so a direct/shareable link to one court's slug lands on the same grid, with
 * that court's column brought to the front. Neither route filters any other
 * court out: the whole point of the grid is seeing every court side by side.
 * They share one query-building method (widgetProps()) so the two entry
 * points cannot drift apart.
 */
class PublicCourtController extends Controller
{
    /**
     * How far ahead the public schedule is published. Anything beyond this is
     * simply not offered yet — it keeps the payload bounded whatever the admin
     * generates.
     */
    private const HORIZON_DAYS = 60;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PricingService $pricing,
    ) {}

    /* ===================================================================== */
    /* GET /  — the merged booking widget                                    */
    /* ===================================================================== */

    public function index(Request $request): Response
    {
        return Inertia::render('PublicSite/CourtsIndex', $this->widgetProps($request));
    }

    /* ===================================================================== */
    /* GET /courts/{court:slug} — the same widget, that court's column first */
    /* ===================================================================== */

    public function show(Request $request, Court $court): Response
    {
        // A deactivated court must not stay reachable by its old link.
        abort_unless((bool) $court->is_active, 404);

        return Inertia::render('PublicSite/CourtsIndex', $this->widgetProps($request, $court));
    }

    /* ===================================================================== */
    /* Shared props builder                                                  */
    /* ===================================================================== */

    /**
     * Everything the merged widget needs, for either entry point: the active
     * courts (ordered so a shared/highlighted court leads), the date
     * navigator across the horizon, the resolved selected date, and that
     * day's full grid — every open time-of-day, across every court.
     *
     * @return array<string, mixed>
     */
    private function widgetProps(Request $request, ?Court $preselected = null): array
    {
        $courts = Court::query()
            ->active()
            ->ordered()
            // Live "taking bookings" indicator for the hero. One correlated
            // subquery for the whole page, evaluated by the database, not N
            // round trips.
            ->withCount([
                'slots as available_slots_count' => fn (Builder $query): Builder => $query
                    ->where('status', CourtSlot::STATUS_AVAILABLE)
                    ->upcoming(),
            ])
            ->get(['id', 'name', 'slug', 'description', 'photo_path', 'sort_order']);

        $company = $this->companyBlurb();
        $holdMinutes = (int) ($this->settings->get(Setting::GROUP_SYSTEM, 'booking_hold_minutes') ?: config('booking.hold_minutes', 30));

        if ($courts->isEmpty()) {
            return [
                'courts' => [],
                'court' => null,
                'days' => [],
                'selectedDate' => null,
                'grid' => null,
                'holdMinutes' => $holdMinutes,
                'horizonDays' => self::HORIZON_DAYS,
                'company' => $company,
                'codePrefix' => Booking::codePrefix(),
            ];
        }

        // Which court leads the column order: an explicit route-bound court
        // (direct/shareable per-court links), then a `?court=` slug, then the
        // first active court that still has anything open, then simply the
        // first active court in display order. Every OTHER active court still
        // renders — this only decides which column is first in view.
        $highlight = $this->resolveHighlightCourt($request->query('court'), $courts, $preselected);
        $courts = $this->leadWith($courts, $highlight);

        $today = Carbon::today();
        $horizon = $today->copy()->addDays(self::HORIZON_DAYS);

        $days = $this->openDays($courts->pluck('id')->all(), $today, $horizon);
        $selectedDate = $this->resolveSelectedDate($request->query('date'), $days, $today, $horizon);

        $grid = $selectedDate === null
            ? ['sections' => [], 'rates' => [], 'courtSummary' => []]
            : $this->buildGrid($courts, $selectedDate, $today);

        return [
            // One card per active court, now rendered as a grid column
            // header rather than a switcher pill.
            'courts' => $courts->map(function (Court $court) use ($grid): array {
                $summary = $grid['courtSummary'][$court->getKey()] ?? null;

                return [
                    'id' => $court->getKey(),
                    'name' => (string) $court->name,
                    'slug' => (string) $court->slug,
                    'description' => $court->description,
                    'photo_url' => $this->publicUrl($court->photo_path),
                    // Pricing is club-wide: a court with nothing open today shows
                    // the global "from" rate rather than a per-court base price.
                    'from_price' => $summary['from_price'] ?? $this->pricing->fromRate(),
                    'available_slots_count' => (int) ($court->available_slots_count ?? 0),
                    'available_today_count' => (int) ($summary['available_count'] ?? 0),
                ];
            })->all(),

            // The court leading the grid — drives the hero copy/photo only.
            'court' => [
                'id' => $highlight->getKey(),
                'name' => (string) $highlight->name,
                'slug' => (string) $highlight->slug,
                'description' => $highlight->description,
                'photo_url' => $this->publicUrl($highlight->photo_path),
                'from_price' => $this->pricing->fromRate(),
            ],

            // Every day in the horizon that still has something to sell, on
            // ANY active court.
            'days' => $days,

            'selectedDate' => $selectedDate,

            // The whole grid for the chosen day only. The client re-requests
            // just this key (plus 'days'/'selectedDate'/'courts') when the
            // date changes.
            'grid' => [
                'sections' => $grid['sections'],
                'rates' => $grid['rates'],
            ],

            'holdMinutes' => $holdMinutes,

            'horizonDays' => self::HORIZON_DAYS,

            'company' => $company,

            'codePrefix' => Booking::codePrefix(),
        ];
    }

    /**
     * @param  Collection<int, Court>  $courts
     */
    private function resolveHighlightCourt(mixed $requestedSlug, Collection $courts, ?Court $preselected): Court
    {
        if ($preselected !== null) {
            // Reuse the already-loaded row (with its availability count) when
            // it is the same court, so this never costs an extra query.
            return $courts->first(fn (Court $court): bool => $court->is($preselected)) ?? $preselected;
        }

        if (is_string($requestedSlug) && $requestedSlug !== '') {
            $match = $courts->first(fn (Court $court): bool => $court->slug === $requestedSlug);

            if ($match !== null) {
                return $match;
            }
        }

        return $courts->first(fn (Court $court): bool => ($court->available_slots_count ?? 0) > 0)
            ?? $courts->first();
    }

    /**
     * Reorder the active-court list so the highlighted court's column is
     * first — the mechanism behind `public.courts.show`'s "scroll this
     * court's column into view" requirement. Every other court keeps its
     * relative display order behind it.
     *
     * @param  Collection<int, Court>  $courts
     * @return Collection<int, Court>
     */
    private function leadWith(Collection $courts, Court $highlight): Collection
    {
        $lead = $courts->first(fn (Court $court): bool => $court->is($highlight)) ?? $highlight;
        $rest = $courts->reject(fn (Court $court): bool => $court->is($lead))->values();

        return collect([$lead])->merge($rest)->values();
    }

    /* ===================================================================== */
    /* Query helpers                                                          */
    /* ===================================================================== */

    /**
     * The date navigator: one row per day that still has an open, upcoming,
     * unblocked slot on ANY active court. Aggregated in the database — never
     * by loading the slots.
     *
     * @param  list<int>  $courtIds
     * @return list<array<string, mixed>>
     */
    private function openDays(array $courtIds, Carbon $from, Carbon $to): array
    {
        if ($courtIds === []) {
            return [];
        }

        return CourtSlot::query()
            ->whereIn('court_id', $courtIds)
            ->where('status', CourtSlot::STATUS_AVAILABLE)
            ->upcoming()
            ->whereBetween('slot_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('slot_date')
            ->orderBy('slot_date')
            ->selectRaw('slot_date, COUNT(*) as slots_count, MIN(price) as from_price')
            ->toBase()
            ->get()
            ->map(function (stdClass $row) use ($from): array {
                $date = $this->parse($row->slot_date ?? null) ?? $from->copy();

                return [
                    'date' => $date->toDateString(),
                    'weekday' => $date->isoFormat('ddd'),
                    'day' => $date->isoFormat('D'),
                    'month' => $date->isoFormat('MMM'),
                    'long_label' => $this->friendlyDate($date, $from),
                    'is_today' => $date->isSameDay($from),
                    'is_tomorrow' => $date->isSameDay($from->copy()->addDay()),
                    'slots_count' => (int) $row->slots_count,
                    'from_price' => (float) $row->from_price,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The spreadsheet grid for one day: every distinct (start_time, end_time)
     * pair appearing on ANY active court, in ANY status, becomes one row;
     * every active court becomes one column. Built from a single query and
     * pivoted in PHP, so the cost never scales with the number of courts or
     * rows.
     *
     * @param  Collection<int, Court>  $courts
     * @return array{
     *     sections: list<array<string, mixed>>,
     *     rates: list<array<string, mixed>>,
     *     courtSummary: array<int, array{available_count: int, from_price: float|null}>,
     * }
     */
    private function buildGrid(Collection $courts, string $date, Carbon $today): array
    {
        $courtIds = $courts->pluck('id')->all();

        $slots = CourtSlot::query()
            ->whereIn('court_id', $courtIds)
            ->whereDate('slot_date', $date)
            ->get(['id', 'court_id', 'start_time', 'end_time', 'price', 'status']);

        $empty = ['sections' => [], 'rates' => [], 'courtSummary' => []];

        if ($slots->isEmpty()) {
            return $empty;
        }

        $isToday = $date === $today->toDateString();
        $nowTime = Carbon::now()->format('H:i:s');

        // Union every (start, end) pair across every court into one row set.
        /** @var array<string, array{start_time: string, end_time: string, cells: array<int, CourtSlot>}> $rowBuckets */
        $rowBuckets = [];

        foreach ($slots as $slot) {
            $startRaw = (string) $slot->start_time;
            $endRaw = (string) $slot->end_time;

            // Today only: a row nobody can book any more is just noise.
            if ($isToday && $startRaw <= $nowTime) {
                continue;
            }

            $key = $startRaw.'|'.$endRaw;

            $rowBuckets[$key] ??= [
                'start_time' => $startRaw,
                'end_time' => $endRaw,
                'cells' => [],
            ];

            $rowBuckets[$key]['cells'][(int) $slot->court_id] = $slot;
        }

        if ($rowBuckets === []) {
            return $empty;
        }

        // Fixed-width HH:MM:SS strings sort lexically in chronological order.
        ksort($rowBuckets);

        /** @var array<int, array{available_count: int, from_price: float|null}> $courtSummary */
        $courtSummary = [];

        foreach ($courts as $court) {
            $courtSummary[$court->getKey()] = ['available_count' => 0, 'from_price' => null];
        }

        $rows = [];

        foreach ($rowBuckets as $bucket) {
            $start = $this->combine($date, $bucket['start_time']);
            $end = $this->combine($date, $bucket['end_time']);

            // An end time earlier than the start means the slot runs past
            // midnight — the same convention as CourtSlot::endsAt().
            if ($end->lessThanOrEqualTo($start)) {
                $end = $end->copy()->addDay();
            }

            $duration = (int) round($start->diffInMinutes($end, true));
            $period = $this->periodOf($start);

            $cells = [];
            $rowPrices = [];

            foreach ($courts as $court) {
                $courtId = $court->getKey();
                /** @var CourtSlot|null $slot */
                $slot = $bucket['cells'][$courtId] ?? null;

                // No slot was ever scheduled here for this court, or the
                // admin blocked it — a customer cannot tell those apart.
                if ($slot === null || $slot->status === CourtSlot::STATUS_BLOCKED) {
                    $cells[$courtId] = ['status' => 'unavailable', 'id' => null, 'price' => null];

                    continue;
                }

                $status = match ($slot->status) {
                    CourtSlot::STATUS_AVAILABLE => 'available',
                    CourtSlot::STATUS_HELD => 'pending',
                    CourtSlot::STATUS_BOOKED => 'booked',
                    default => 'unavailable',
                };

                $price = (float) $slot->price;

                $cells[$courtId] = [
                    'status' => $status,
                    'id' => $status === 'available' ? $slot->getKey() : null,
                    'price' => $status === 'available' ? $price : null,
                ];

                $rowPrices[] = $price;

                if ($status === 'available') {
                    $courtSummary[$courtId]['available_count']++;

                    if ($courtSummary[$courtId]['from_price'] === null || $price < $courtSummary[$courtId]['from_price']) {
                        $courtSummary[$courtId]['from_price'] = $price;
                    }
                }
            }

            $rows[] = [
                'key' => $bucket['start_time'].'-'.$bucket['end_time'],
                'start' => $start->format('g:i A'),
                'end' => $end->format('g:i A'),
                'time_range' => $start->format('g:i A').' – '.$end->format('g:i A'),
                'duration_minutes' => $duration,
                'period' => $period,
                // Representative rate for this time-of-day, across courts —
                // the cheapest price on offer at that time. Feeds the rate
                // bands below; not shown per-cell (each cell has its own).
                'price' => $rowPrices === [] ? null : min($rowPrices),
                'cells' => $cells,
            ];
        }

        if ($rows === []) {
            return $empty;
        }

        $rates = $this->rateBands($rows);

        $sections = [];

        foreach (['Morning', 'Afternoon', 'Evening'] as $period) {
            $periodRows = array_values(array_filter($rows, fn (array $row): bool => $row['period'] === $period));

            if ($periodRows !== []) {
                $sections[] = ['period' => $period, 'rows' => $periodRows];
            }
        }

        return ['sections' => $sections, 'rates' => $rates, 'courtSummary' => $courtSummary];
    }

    /**
     * The "P650 12AM-8AM · P600 8AM-4PM" rate summary: walk the day's rows in
     * time order and merge consecutive rows sharing the same representative
     * price into one time-range band.
     *
     * @param  list<array<string, mixed>>  $rows  already sorted by start time
     * @return list<array{price: float, start: string, end: string}>
     */
    private function rateBands(array $rows): array
    {
        $bands = [];

        foreach ($rows as $row) {
            if ($row['price'] === null) {
                continue;
            }

            $lastIndex = array_key_last($bands);

            if ($lastIndex !== null && abs($bands[$lastIndex]['price'] - $row['price']) < 0.005) {
                $bands[$lastIndex]['end'] = $row['end'];

                continue;
            }

            $bands[] = ['price' => $row['price'], 'start' => $row['start'], 'end' => $row['end']];
        }

        return array_values($bands);
    }

    /* ===================================================================== */
    /* Presentation helpers                                                   */
    /* ===================================================================== */

    /**
     * Pick the day being shown: the requested one when it is genuinely open,
     * otherwise the first day that has anything left.
     *
     * @param  list<array<string, mixed>>  $days
     */
    private function resolveSelectedDate(mixed $requested, array $days, Carbon $from, Carbon $to): ?string
    {
        if ($days === []) {
            return null;
        }

        $openDates = array_column($days, 'date');

        if (is_string($requested) && $requested !== '') {
            $candidate = $this->parse($requested);

            if ($candidate !== null
                && $candidate->betweenIncluded($from, $to)
                && in_array($candidate->toDateString(), $openDates, true)) {
                return $candidate->toDateString();
            }
        }

        return (string) $openDates[0];
    }

    /**
     * "Today", "Tomorrow", then a spelled-out date — the way a person reads a
     * calendar rather than the way a database stores one.
     */
    private function friendlyDate(Carbon $date, Carbon $today): string
    {
        if ($date->isSameDay($today)) {
            return 'Today · '.$date->isoFormat('ddd, D MMMM');
        }

        if ($date->isSameDay($today->copy()->addDay())) {
            return 'Tomorrow · '.$date->isoFormat('ddd, D MMMM');
        }

        return $date->isoFormat('dddd, D MMMM YYYY');
    }

    /**
     * Grid rows read far better when the day is chunked into parts.
     *
     * The boundaries themselves are NOT restated here — Court::periodForHour()
     * owns them, because the same three periods now also decide what a slot
     * costs. This method only puts the display capitalisation on the model's
     * lowercase key.
     */
    private function periodOf(Carbon $start): string
    {
        return ucfirst(Court::periodForHour((int) $start->format('G')));
    }

    /**
     * Combine a date string with a raw "H:i:s" TIME column value.
     */
    private function combine(string $date, string $time): Carbon
    {
        return Carbon::parse($date.' '.$time);
    }

    /**
     * A public storage URL, or null when nothing has been uploaded — pages must
     * render a designed placeholder rather than a broken image.
     */
    private function publicUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Company details for the public footer/hero. Read through the cached
     * settings repository, never a raw query.
     *
     * @return array<string, string|null>
     */
    private function companyBlurb(): array
    {
        // Keys are stored prefixed (`company_name`, not `name`) — that is the
        // shape Admin > Settings > Company writes, so read the same names or
        // the public site silently shows nothing however the admin fills it in.
        return [
            'name' => $this->stringSetting('company_name'),
            'tagline' => $this->stringSetting('company_tagline'),
            'phone' => $this->stringSetting('company_phone'),
            'address' => $this->stringSetting('company_address'),
        ];
    }

    private function stringSetting(string $key): ?string
    {
        $value = $this->settings->get('company', $key);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * Parse a database date/datetime string without ever throwing on junk.
     */
    private function parse(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
