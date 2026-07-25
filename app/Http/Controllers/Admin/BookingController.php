<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\RejectBookingRequest;
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
     * A booking still sitting on its slot is cancelled through the service
     * first — deleting the row on its own would strand the slot in `held`
     * with a `held_booking_id` pointing at nothing, quietly taking a
     * sellable hour off the market forever.
     */
    public function destroy(Request $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('delete', $booking);

        $code = (string) $booking->code;

        if ($booking->isHolding()) {
            $this->bookings->cancel($booking);
        }

        // The Auditable trait records the deletion.
        $booking->delete();

        $message = sprintf('Booking %s deleted.', $code);

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

        $defaultPerPage = (int) config('booking.per_page', 25);
        $perPage = (int) $this->stringQuery($request, 'per_page', (string) $defaultPerPage);

        return [
            'search' => Str::limit(trim($this->stringQuery($request, 'search')), 100, ''),
            'status' => in_array($status, Booking::STATUSES, true) ? $status : '',
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

        return array_merge($this->row($booking), [
            'notes' => (string) $booking->notes,

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
            'delete' => Gate::allows('delete', Booking::class),
        ];
    }

    private function canConfirm(Booking $booking): bool
    {
        return $booking->status === Booking::STATUS_PENDING_VERIFICATION
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
