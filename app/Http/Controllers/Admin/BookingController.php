<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\RejectBookingRequest;
use App\Http\Requests\Booking\StoreManualBookingRequest;
use App\Models\AuditTrail;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\BookingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The verify loop.
 *
 * This is the screen the admin lives on: read the payment reference, check it
 * against the receiving account on their phone, then confirm or reject. Every
 * design decision here serves that loop — pending verification sorts to the
 * top by default, the reference number is copyable, the payload names which
 * app the reference belongs to, and the confirm dialog restates the amount so
 * a mis-click cannot take money for the wrong booking.
 *
 * State transitions are NOT implemented here. They live in BookingService,
 * which owns the locking, the idempotency and the slot side effects; this
 * controller validates, authorises, delegates and flashes.
 */
class BookingController extends Controller
{
    /** Columns the table may be sorted by, mapped to their qualified SQL name. */
    private const SORTABLE = [
        'code' => 'bookings.code',
        'customer_name' => 'bookings.customer_name',
        'amount' => 'bookings.amount',
        'status' => 'bookings.status',
        'created_at' => 'bookings.created_at',
        'slot_date' => 'court_slots.slot_date',
        'hold_expires_at' => 'bookings.hold_expires_at',
    ];

    private const MIN_PER_PAGE = 10;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly BookingService $bookings,
        private readonly AuditTrailService $audit,
    ) {}

    /* ===================================================================== */
    /* Index — the queue                                                      */
    /* ===================================================================== */

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Booking::class);

        $filters = $this->filters($request);

        $paginator = $this->query($filters)
            ->with([
                'court:id,name,code,slug',
                'slot:id,court_id,slot_date,start_time,end_time,status',
                // Each slot's own court, so a cross-court booking can name every
                // court it spans without an N+1 walk over the queue.
                'slot.court:id,name',
                'slots:id,court_id,held_booking_id,slot_date,start_time,end_time,status',
                'slots.court:id,name',
                'confirmedBy:id,name,username',
            ])
            ->select('bookings.*')
            ->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->withQueryString();

        $paginator->through(fn (Booking $booking): array => $this->row($booking));

        return Inertia::render('Admin/Bookings/Index', [
            'bookings' => $paginator,
            'filters' => $this->filterProps($filters),
            'courts' => $this->courtOptions(),
            'statusCounts' => $this->statusCounts($filters),
            'can' => $this->abilities(),
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    /* ===================================================================== */
    /* Show — everything needed to make the call                              */
    /* ===================================================================== */

    public function show(Request $request, Booking $booking): Response
    {
        Gate::authorize('view', $booking);

        $booking->load([
            'court:id,name,code,slug',
            'slot:id,court_id,slot_date,start_time,end_time,price,status',
            // The court behind each slot — the detail screen names every court a
            // combined booking spans, so this must be loaded, not lazily walked.
            'slot.court:id,name',
            'slots:id,court_id,held_booking_id,slot_date,start_time,end_time,status',
            'slots.court:id,name',
            'confirmedBy:id,name,username',
            'rejectedBy:id,name,username',
            'createdBy:id,name,username',
        ]);

        $this->audit->logView(
            'Bookings',
            sprintf('Viewed booking %s (%s).', (string) $booking->code, (string) $booking->customer_name),
            $booking,
        );

        return Inertia::render('Admin/Bookings/Show', [
            'booking' => $this->detail($booking),
            'timeline' => $this->timeline($booking),
            'can' => $this->abilities(),
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    /* ===================================================================== */
    /* Create — the desk keys one in                                          */
    /* ===================================================================== */

    /**
     * The manual booking form: pick a day, pick hours off that day's grid,
     * name the customer.
     *
     * Only slots that already exist can be picked. Generating inventory is the
     * Slots screen's job and it stays there — a booking form that could
     * conjure an hour out of nothing would let one staff member sell a court
     * outside the club's operating hours without anyone ever deciding those
     * hours had changed.
     *
     * `?date=` is unbounded on purpose, in both directions. Forward is
     * ordinary; backward is the backfill case, where last night's walk-in
     * finally gets written down.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Booking::class);

        $date = $this->date($this->stringQuery($request, 'date')) ?? now()->toDateString();

        return Inertia::render('Admin/Bookings/Create', [
            'courts' => $this->bookableCourts(),
            // One prop for everything that depends on the chosen day, so the
            // date picker can re-request exactly this key and nothing else.
            'schedule' => $this->schedule($date),
            'selectedDate' => $date,
            'today' => now()->toDateString(),
            'modes' => $this->modeOptions(),
        ]);
    }

    /**
     * Write the booking.
     *
     * No try/catch: BookingException renders itself as a flash redirect, so a
     * slot lost to a real customer between opening this form and submitting it
     * comes back as a readable message on the form rather than a 500.
     */
    public function store(StoreManualBookingRequest $request): RedirectResponse
    {
        $booking = $this->bookings->reserveManually(
            $request->slotIds(),
            $request->customer(),
            $this->actor($request),
            $request->mode(),
        );

        return redirect()
            ->route('admin.bookings.show', $booking->code)
            ->with('success', $this->createdMessage($booking));
    }

    /* ===================================================================== */
    /* Transitions — thin wrappers over the state machine                     */
    /* ===================================================================== */

    public function confirm(Request $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('confirm', $booking);

        $this->bookings->confirm($booking, $this->actor($request));

        return back()->with('success', sprintf(
            'Booking %s confirmed. The court is now reserved and the customer has been notified.',
            (string) $booking->code,
        ));
    }

    public function reject(RejectBookingRequest $request, Booking $booking): RedirectResponse
    {
        $this->bookings->reject($booking, $this->actor($request), $request->reason());

        return back()->with('success', sprintf(
            'Booking %s rejected. The slot is back on sale and the customer has been notified.',
            (string) $booking->code,
        ));
    }

    public function complete(Request $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('complete', $booking);

        $this->bookings->complete($booking, $this->actor($request));

        return back()->with('success', sprintf(
            'Booking %s marked as completed.',
            (string) $booking->code,
        ));
    }

    /**
     * Remove a junk record.
     *
     * Deleting the row on its own would strand every slot the booking holds
     * with a `held_booking_id` pointing at nothing, quietly taking sellable
     * hours off the market forever — invisible here, still unbookable on the
     * public grid. Both release paths below exist to prevent that:
     *
     *  - a booking still on hold goes through cancel() first, so it lands in
     *    `cancelled` with the audit entry a customer-facing booking deserves;
     *  - releaseSlotsOnDeletion() then sweeps whatever any other status left
     *    occupied. This is the part cancel() alone cannot cover: confirmed and
     *    completed bookings hold their slots as `booked`, which no
     *    status-gated release ever touches.
     */
    public function destroy(Request $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('delete', $booking);

        $code = (string) $booking->code;

        if ($booking->isHolding()) {
            $this->bookings->cancel($booking);
        }

        // No-op when cancel() above already handed the slots back.
        $freed = $this->bookings->releaseSlotsOnDeletion($booking);

        // The Auditable trait records the deletion.
        $booking->delete();

        $message = $freed > 0
            ? sprintf('Booking %s deleted. %d %s returned to available.', $code, $freed, Str::plural('slot', $freed))
            : sprintf('Booking %s deleted.', $code);

        // Going "back" from the detail screen would land on a 404.
        return $this->cameFromDetail($booking)
            ? redirect()->route('admin.bookings.index')->with('success', $message)
            : back()->with('success', $message);
    }

    /* ===================================================================== */
    /* Query building                                                         */
    /* ===================================================================== */

    /**
     * Read and clamp every query-string parameter useDataTable sends.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $sort = $this->stringQuery($request, 'sort');
        $direction = strtolower($this->stringQuery($request, 'direction', 'desc'));
        $status = $this->stringQuery($request, 'status');
        $source = $this->stringQuery($request, 'source');

        $defaultPerPage = (int) config('booking.per_page', 25);
        $perPage = (int) $this->stringQuery($request, 'per_page', (string) $defaultPerPage);

        return [
            'search' => Str::limit(trim($this->stringQuery($request, 'search')), 100, ''),
            'status' => in_array($status, Booking::STATUSES, true) ? $status : '',
            // Blank means both, which is the default: staff work one queue.
            'source' => in_array($source, Booking::SOURCES, true) ? $source : '',
            'court_id' => (int) $this->stringQuery($request, 'court_id', '0'),
            'date_from' => $this->date($this->stringQuery($request, 'date_from')),
            'date_to' => $this->date($this->stringQuery($request, 'date_to')),
            'sort' => array_key_exists($sort, self::SORTABLE) ? $sort : '',
            'direction' => $direction === 'asc' ? 'asc' : 'desc',
            'per_page' => max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage > 0 ? $perPage : $defaultPerPage)),
            'page' => max(1, (int) $this->stringQuery($request, 'page', '1')),
        ];
    }

    /**
     * Query-string reader that tolerates array injection (`?search[]=x`),
     * which would otherwise fatal on a string cast.
     */
    private function stringQuery(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Echo the filters back so useDataTable and the tabs stay in sync after a
     * `->withQueryString()` round trip.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function filterProps(array $filters): array
    {
        return [
            'search' => $filters['search'],
            'status' => $filters['status'],
            'source' => $filters['source'],
            'court_id' => $filters['court_id'] > 0 ? (string) $filters['court_id'] : '',
            'date_from' => $filters['date_from'] ?? '',
            'date_to' => $filters['date_to'] ?? '',
            'sort' => $filters['sort'],
            'direction' => $filters['direction'],
            'per_page' => $filters['per_page'],
        ];
    }

    /**
     * The filtered, sorted booking query.
     *
     * `court_slots` is joined rather than filtered through `whereHas` so the
     * play date can be both filtered AND sorted on, and so the per-status
     * count query stays a single round trip. Every column is qualified: both
     * tables carry `id`, `court_id`, `status` and `created_at`.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Booking>
     */
    private function query(array $filters, bool $applyStatus = true): Builder
    {
        $query = Booking::query()
            ->leftJoin('court_slots', 'court_slots.id', '=', 'bookings.court_slot_id')
            ->when($filters['search'] !== '', fn (Builder $q): Builder => $q->search($filters['search']))
            ->when($filters['source'] !== '', fn (Builder $q): Builder => $q->source($filters['source']))
            ->when($filters['court_id'] > 0, fn (Builder $q): Builder => $q->where('bookings.court_id', $filters['court_id']))
            // Compared bare rather than through whereDate(): slot_date is a
            // DATE column, and wrapping it in DATE() would stop the
            // (court_id, slot_date, status) index being used.
            ->when($filters['date_from'] !== null, fn (Builder $q): Builder => $q->where('court_slots.slot_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== null, fn (Builder $q): Builder => $q->where('court_slots.slot_date', '<=', $filters['date_to']))
            ->when(
                $applyStatus && $filters['status'] !== '',
                fn (Builder $q): Builder => $q->where('bookings.status', $filters['status']),
            );

        return $this->applySort($query, $filters);
    }

    /**
     * Default ordering puts the actionable queue first.
     *
     * Pending verification is work waiting on a human, so it leads; awaiting
     * payment is work waiting on the customer and comes next; everything else
     * is history. Within the queue the oldest hold sorts first — that is the
     * one closest to expiring.
     *
     * @param  Builder<Booking>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Booking>
     */
    private function applySort(Builder $query, array $filters): Builder
    {
        if ($filters['sort'] !== '') {
            $column = self::SORTABLE[$filters['sort']];

            $query->orderBy($column, $filters['direction']);

            // Slot date alone is ambiguous — two slots on the same day must
            // still come back in playing order.
            if ($filters['sort'] === 'slot_date') {
                $query->orderBy('court_slots.start_time', $filters['direction']);
            }

            return $query->orderBy('bookings.id', 'desc');
        }

        return $query
            ->orderByRaw('CASE bookings.status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [
                Booking::STATUS_PENDING_VERIFICATION,
                Booking::STATUS_AWAITING_PAYMENT,
            ])
            ->orderByRaw('CASE WHEN bookings.hold_expires_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('bookings.hold_expires_at')
            ->orderByDesc('bookings.created_at')
            ->orderByDesc('bookings.id');
    }

    /**
     * Counts per status for the filter tabs, honouring every filter EXCEPT
     * status itself — otherwise the tab you are standing on would be the only
     * one with a number.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function statusCounts(array $filters): array
    {
        /** @var \Illuminate\Support\Collection<int|string, int> $rows */
        $rows = $this->query($filters, applyStatus: false)
            ->reorder()
            ->toBase()
            ->selectRaw('bookings.status as status, COUNT(*) as aggregate')
            ->groupBy('bookings.status')
            ->pluck('aggregate', 'status');

        $counts = ['all' => 0];

        foreach (Booking::STATUSES as $status) {
            $counts[$status] = 0;
        }

        foreach ($rows as $status => $aggregate) {
            $counts[(string) $status] = (int) $aggregate;
            $counts['all'] += (int) $aggregate;
        }

        return $counts;
    }

    /**
     * Courts that actually appear in the booking history — a filter listing
     * courts with no bookings is noise.
     *
     * @return array<int, array<string, mixed>>
     */
    private function courtOptions(): array
    {
        return Court::query()
            ->withTrashed()
            ->whereIn('id', Booking::query()->select('court_id')->distinct())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Court $court): array => [
                'value' => (string) $court->getKey(),
                'label' => (string) $court->name,
            ])
            ->all();
    }

    /* ===================================================================== */
    /* Manual booking form                                                    */
    /* ===================================================================== */

    /**
     * The courts the form may put a booking on: active ones, in display order.
     *
     * Unlike courtOptions() above — which lists whatever courts *have* history
     * so the filter can find it, deleted ones included — this list is about
     * what may be sold now, so it takes the live set only.
     *
     * @return list<array<string, mixed>>
     */
    private function bookableCourts(): array
    {
        return Court::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'category'])
            ->map(static fn (Court $court): array => [
                'id' => $court->getKey(),
                'name' => (string) $court->name,
                'category_label' => $court->categoryLabel(),
            ])
            ->values()
            ->all();
    }

    /**
     * One day's inventory as a time-by-court grid.
     *
     * Every slot on the day is returned, not just the free ones. A grid that
     * hid taken hours would read as "nothing exists here" for a fully booked
     * evening, which is the moment staff most need to see who has what — so
     * unavailable cells come back labelled and unselectable rather than blank.
     *
     * @return array<string, mixed>
     */
    private function schedule(string $date): array
    {
        $courtIds = array_column($this->bookableCourts(), 'id');

        $slots = $courtIds === []
            ? new Collection
            : CourtSlot::query()
                ->whereIn('court_id', $courtIds)
                ->forDate($date)
                ->orderBy('start_time')
                ->get(['id', 'court_id', 'slot_date', 'start_time', 'end_time', 'price', 'status', 'held_booking_id']);

        // Group by the wall-clock range rather than by start time alone: two
        // courts may run different slot lengths on the same day, and merging
        // an 8–9 with an 8–10 into one row would misprice whichever the staff
        // member happened to read off the other column.
        $rows = $slots
            ->groupBy(fn (CourtSlot $slot): string => $slot->time_range)
            ->map(function (Collection $group, string $range): array {
                $first = $group->first();

                return [
                    'key' => $range,
                    'time_range' => $range,
                    'starts_at' => $first->startsAt()->toIso8601String(),
                    'has_started' => $first->hasStarted(),
                    // Whether this hour is over, which is what actually decides
                    // `completed` vs `confirmed` server-side. Sent per row, not
                    // inferred from the day: an evening session finished an hour
                    // ago is a backfill even though its date is still today.
                    'has_ended' => $first->endsAt()->isPast(),
                    'cells' => $group
                        ->mapWithKeys(fn (CourtSlot $slot): array => [
                            (string) $slot->court_id => $this->pickerCell($slot),
                        ])
                        ->all(),
                ];
            })
            ->sortBy('starts_at')
            ->values()
            ->all();

        $day = Carbon::parse($date);

        return [
            'date' => $date,
            'date_label' => $day->format('l, j F Y'),
            'is_past' => $day->isPast() && ! $day->isToday(),
            'rows' => $rows,
            'available_count' => $slots
                ->filter(fn (CourtSlot $slot): bool => $slot->status === CourtSlot::STATUS_AVAILABLE)
                ->count(),
            'slot_count' => $slots->count(),
        ];
    }

    /**
     * One cell of the picker grid.
     *
     * `selectable` deliberately ignores whether the hour has already started —
     * a past slot that nobody took is exactly what a backfill needs to pick.
     * The only thing that disqualifies a slot here is somebody else already
     * having it, or the club having taken it off the market.
     *
     * @return array<string, mixed>
     */
    private function pickerCell(CourtSlot $slot): array
    {
        $status = (string) $slot->status;

        return [
            'id' => $slot->getKey(),
            'status' => $status,
            'price' => (float) $slot->price,
            'price_formatted' => $this->money($slot->price),
            'selectable' => $status === CourtSlot::STATUS_AVAILABLE,
            'label' => match ($status) {
                CourtSlot::STATUS_AVAILABLE => $this->money($slot->price),
                CourtSlot::STATUS_HELD => 'On hold',
                CourtSlot::STATUS_BOOKED => 'Booked',
                CourtSlot::STATUS_BLOCKED => 'Blocked',
                default => ucfirst($status),
            },
        ];
    }

    /**
     * The two ways a desk-made booking can land, as the form renders them.
     *
     * @return list<array<string, string>>
     */
    private function modeOptions(): array
    {
        return [
            [
                'value' => BookingService::MANUAL_MODE_CONFIRMED,
                'label' => 'Confirmed — already settled',
                'description' => 'The court is sold. Use this when the customer has paid, or the owner has given it to them.',
            ],
            [
                'value' => BookingService::MANUAL_MODE_RESERVED,
                'label' => 'Reserved — waiting for payment',
                'description' => 'The court is held with no expiry. Confirm it later when the payment comes in.',
            ],
        ];
    }

    /**
     * What the staff member is told after the booking is written.
     *
     * The status is restated rather than assumed, because it is not always the
     * one they chose: a session that has already finished is recorded as
     * completed whatever mode was picked, and silently doing that would leave
     * them looking for a confirmed booking that does not exist.
     */
    private function createdMessage(Booking $booking): string
    {
        return match ((string) $booking->status) {
            Booking::STATUS_COMPLETED => sprintf(
                'Booking %s recorded as completed — that schedule has already finished.',
                (string) $booking->code,
            ),
            Booking::STATUS_CONFIRMED => sprintf(
                'Booking %s created and confirmed. The court is reserved.',
                (string) $booking->code,
            ),
            default => sprintf(
                'Booking %s created and held with no expiry. Confirm it once the payment comes in.',
                (string) $booking->code,
            ),
        };
    }

    /* ===================================================================== */
    /* Presentation                                                           */
    /* ===================================================================== */

    /**
     * One row of the queue.
     *
     * @return array<string, mixed>
     */
    private function row(Booking $booking): array
    {
        $slot = $booking->relationLoaded('slot') ? $booking->getRelation('slot') : null;
        $slots = $booking->relationLoaded('slots') ? $booking->getRelation('slots') : collect();
        $court = $booking->relationLoaded('court') ? $booking->getRelation('court') : null;
        $confirmedBy = $booking->relationLoaded('confirmedBy') ? $booking->getRelation('confirmedBy') : null;

        return [
            'id' => $booking->getKey(),
            'code' => (string) $booking->code,
            'status' => (string) $booking->status,
            // Where it came from. The queue badges manual bookings because
            // "no payment proof" reads as a missing step on a public booking
            // and as the normal state of affairs on a desk-made one.
            'source' => (string) $booking->source,
            'is_manual' => $booking->isManual(),

            'customer_name' => (string) $booking->customer_name,
            'customer_phone' => (string) $booking->customer_phone,
            'customer_email' => (string) $booking->customer_email,

            'court_name' => $court instanceof Court ? (string) $court->name : null,
            // Every distinct court the booking spans, in playing order. A
            // single-court booking yields a one-element list and is_multi_court
            // false, so its display is unchanged; a combined cross-court booking
            // lists them all so staff scanning the queue aren't misled into
            // reading it as a single-court reservation.
            'court_names' => $this->courtNames($slots),
            'is_multi_court' => $this->isMultiCourt($slots),
            // The primary (earliest) slot, unchanged — every existing caller
            // of this key keeps describing exactly what it always has.
            'slot' => $this->slotPayload($slot instanceof CourtSlot ? $slot : null),
            // The plural, authoritative full list, for screens that need to
            // show every slot a multi-slot booking spans.
            'slots' => $this->slotsPayload($slots),
            'slot_count' => $slots->count(),

            'amount' => (float) $booking->amount,
            'amount_formatted' => $this->money($booking->amount),

            'payment_reference' => (string) $booking->payment_reference,
            // Which app the reference belongs to, so the verifier knows where
            // to look it up. Deliberately left null — not defaulted to GCash —
            // for every booking taken before the second QR went live: a guess
            // here would send staff to the wrong app.
            'payment_method' => filled($booking->payment_method) ? (string) $booking->payment_method : null,
            'payment_method_label' => $booking->paymentMethodLabel(),
            'has_proof' => filled($booking->payment_proof_path),

            'hold_expires_at' => $booking->hold_expires_at?->toIso8601String(),
            'hold_active' => $booking->isHolding() && $booking->hold_expires_at !== null,
            'created_at' => $booking->created_at?->toIso8601String(),
            'created_label' => $booking->created_at?->format('j M Y, g:i A'),

            'confirmed_by' => $confirmedBy instanceof User ? (string) $confirmedBy->name : null,

            'can_confirm' => $this->canConfirm($booking),
            'can_reject' => $this->canReject($booking),
            'can_complete' => $this->canComplete($booking),

            'url' => route('admin.bookings.show', $booking->code),
        ];
    }

    /**
     * Everything the detail screen renders.
     *
     * @return array<string, mixed>
     */
    private function detail(Booking $booking): array
    {
        $slot = $booking->relationLoaded('slot') ? $booking->getRelation('slot') : null;
        $court = $booking->relationLoaded('court') ? $booking->getRelation('court') : null;
        $confirmedBy = $booking->relationLoaded('confirmedBy') ? $booking->getRelation('confirmedBy') : null;
        $rejectedBy = $booking->relationLoaded('rejectedBy') ? $booking->getRelation('rejectedBy') : null;
        $createdBy = $booking->relationLoaded('createdBy') ? $booking->getRelation('createdBy') : null;

        return array_merge($this->row($booking), [
            'notes' => (string) $booking->notes,

            // Who keyed a desk-made booking in. Null on a public booking, and
            // the detail screen renders that null as "the public site" rather
            // than as a blank — a booking nobody keyed in is not a booking of
            // unknown origin.
            'created_by' => $createdBy instanceof User ? (string) $createdBy->name : null,

            'court' => $court instanceof Court ? [
                'id' => $court->getKey(),
                'name' => (string) $court->name,
                'code' => (string) $court->code,
                'url' => route('admin.courts.show', $court->getRouteKey()),
            ] : null,

            'slot_status' => $slot instanceof CourtSlot ? (string) $slot->status : null,

            'payment_proof_url' => $this->proofUrl($booking),
            'payment_submitted_at' => $booking->payment_submitted_at?->toIso8601String(),
            'payment_submitted_label' => $booking->payment_submitted_at?->format('D, j M Y g:i A'),

            'confirmed_at_label' => $booking->confirmed_at?->format('D, j M Y g:i A'),
            'rejected_at_label' => $booking->rejected_at?->format('D, j M Y g:i A'),
            'cancelled_at_label' => $booking->cancelled_at?->format('D, j M Y g:i A'),
            'rejected_by' => $rejectedBy instanceof User ? (string) $rejectedBy->name : null,
            'rejection_reason' => (string) $booking->rejection_reason,
            'confirmed_by' => $confirmedBy instanceof User ? (string) $confirmedBy->name : null,

            'ip_address' => (string) $booking->ip_address,
            'user_agent' => (string) $booking->user_agent,
            'created_full' => $booking->created_at?->format('D, j M Y g:i A'),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function slotPayload(?CourtSlot $slot): ?array
    {
        if (! $slot instanceof CourtSlot) {
            return null;
        }

        try {
            $startsAt = $slot->startsAt();
            $endsAt = $slot->endsAt();
        } catch (Throwable) {
            return null;
        }

        $court = $slot->relationLoaded('court') ? $slot->getRelation('court') : null;

        return [
            'id' => $slot->getKey(),
            // The court this specific slot sits on. Carried per-slot so a booking
            // spanning several courts can label each time with its court; on a
            // single-court booking every slot just repeats the primary court.
            'court_id' => (int) $slot->court_id,
            'court_name' => $court instanceof Court ? (string) $court->name : null,
            'date' => $startsAt->toDateString(),
            'date_label' => $startsAt->format('D, j M Y'),
            'date_short' => $startsAt->format('j M'),
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'start_label' => $startsAt->format('g:i A'),
            'end_label' => $endsAt->format('g:i A'),
            'time_range' => $startsAt->format('g:i A').' – '.$endsAt->format('g:i A'),
            'duration_minutes' => (int) round($startsAt->diffInMinutes($endsAt, true)),
            'status' => (string) $slot->status,
        ];
    }

    /**
     * The plural counterpart of slotPayload(): every slot in the given
     * collection mapped through the exact same per-slot shape, so the
     * frontend gets a consistent shape whether it reads the singular `slot`
     * key or the new plural `slots` key. A slot that fails to resolve its
     * start/end (see slotPayload()'s defensive catch) is simply dropped
     * rather than surfacing a null in the list.
     *
     * @param  Collection<int, CourtSlot>  $slots
     * @return array<int, array<string, mixed>>
     */
    private function slotsPayload(Collection $slots): array
    {
        return $slots
            ->map(fn (CourtSlot $slot): ?array => $this->slotPayload($slot))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Every distinct court a booking spans, named, in playing order.
     *
     * `booking_slots` carries one row per slot with no same-court constraint,
     * so a single combined booking can straddle several courts. `court_name`
     * (singular) still names the primary court for backwards compatibility;
     * this lists them all — deduped by court, first-appearance order — so a
     * display can be honest about a cross-court booking with no schema change.
     *
     * @param  Collection<int, CourtSlot>  $slots
     * @return array<int, string>
     */
    private function courtNames(Collection $slots): array
    {
        return $slots
            ->filter(fn (CourtSlot $slot): bool => $slot->relationLoaded('court') && $slot->getRelation('court') instanceof Court)
            ->unique('court_id')
            ->map(fn (CourtSlot $slot): string => (string) $slot->getRelation('court')->name)
            ->values()
            ->all();
    }

    /**
     * Whether the booking spans more than one court — read from the distinct
     * court ids across its slots (the plural truth), not the singular
     * `bookings.court_id` representative.
     *
     * @param  Collection<int, CourtSlot>  $slots
     */
    private function isMultiCourt(Collection $slots): bool
    {
        return $slots->pluck('court_id')->unique()->count() > 1;
    }

    /**
     * The audit history for this booking, newest first.
     *
     * `view` entries are excluded: they are recorded (§8) and remain visible on
     * the audit trail screen, but every page refresh writes one and they would
     * bury the transitions this timeline exists to show.
     *
     * @return array<int, array<string, mixed>>
     */
    private function timeline(Booking $booking): array
    {
        return AuditTrail::query()
            ->where('auditable_type', $booking->getMorphClass())
            ->where('auditable_id', $booking->getKey())
            ->where('action', '!=', AuditTrail::ACTION_VIEW)
            ->latestFirst()
            ->limit(100)
            ->get(['id', 'user_name', 'role_name', 'action', 'description', 'ip_address', 'created_at'])
            ->map(static fn (AuditTrail $entry): array => [
                'id' => $entry->getKey(),
                'action' => (string) $entry->action,
                'description' => (string) $entry->description,
                'user_name' => (string) ($entry->user_name ?: 'System'),
                'role_name' => (string) $entry->role_name,
                'ip_address' => (string) $entry->ip_address,
                'created_at' => $entry->created_at?->toIso8601String(),
                'created_label' => $entry->created_at?->format('j M Y, g:i A'),
            ])
            ->all();
    }

    /**
     * A browsable URL for the uploaded payment screenshot.
     */
    private function proofUrl(Booking $booking): ?string
    {
        $path = trim((string) $booking->payment_proof_path);

        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '/'])) {
            return $path;
        }

        try {
            $disk = Storage::disk('public');

            return $disk->exists($path) ? $disk->url($path) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /* ===================================================================== */
    /* Small helpers                                                          */
    /* ===================================================================== */

    /**
     * @return array<string, bool>
     */
    private function abilities(): array
    {
        return [
            'verify' => Gate::allows('verify', Booking::class),
            'create' => Gate::allows('create', Booking::class),
            'delete' => Gate::allows('delete', Booking::class),
        ];
    }

    /**
     * Mirrors BookingService::confirmableFrom() — a public booking must have
     * submitted proof first, a desk-made hold has no proof step to wait for
     * and is confirmed straight out of awaiting_payment. Kept in step with the
     * service deliberately: a Confirm button the state machine would refuse is
     * worse than no button at all.
     */
    private function canConfirm(Booking $booking): bool
    {
        $confirmable = $booking->isManual()
            ? [Booking::STATUS_PENDING_VERIFICATION, Booking::STATUS_AWAITING_PAYMENT]
            : [Booking::STATUS_PENDING_VERIFICATION];

        return in_array((string) $booking->status, $confirmable, true)
            && Gate::allows('confirm', $booking);
    }

    private function canReject(Booking $booking): bool
    {
        return $booking->isHolding() && Gate::allows('reject', $booking);
    }

    private function canComplete(Booking $booking): bool
    {
        return $booking->status === Booking::STATUS_CONFIRMED
            && Gate::allows('complete', $booking);
    }

    /**
     * The authenticated staff member performing a transition.
     */
    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function cameFromDetail(Booking $booking): bool
    {
        $previous = (string) url()->previous();

        return $previous !== '' && str_contains($previous, '/bookings/'.(string) $booking->code);
    }

    private function date(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function money(mixed $amount): string
    {
        return '₱'.number_format((float) $amount, 2);
    }
}
