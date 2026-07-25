<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Court\StoreCourtRequest;
use App\Http\Requests\Court\UpdateCourtRequest;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Services\AuditTrailService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Courts CRUD.
 *
 * The court is the top of the booking hierarchy: slots hang off it and
 * bookings hang off slots. That makes deletion dangerous, so `destroy()`
 * refuses while money or a customer expectation is still attached and points
 * the operator at deactivation instead.
 */
class CourtController extends Controller
{
    use AuthorizesRequests;

    /**
     * Whitelisted sort keys → real columns / count aliases. Anything not in
     * this map is ignored, which keeps `?sort=` away from the query builder.
     *
     * @var array<string, string>
     */
    private const SORTABLE = [
        'name' => 'name',
        'code' => 'code',
        'sort_order' => 'sort_order',
        'created_at' => 'created_at',
        'available_slots_count' => 'available_slots_count',
        'total_slots_count' => 'total_slots_count',
        'bookings_count' => 'bookings_count',
    ];

    /** @var list<int> */
    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /**
     * Bookings that still represent a live claim on the court: money is either
     * in flight or already taken.
     *
     * @var list<string>
     */
    private const LIVE_BOOKING_STATUSES = [
        Booking::STATUS_AWAITING_PAYMENT,
        Booking::STATUS_PENDING_VERIFICATION,
        Booking::STATUS_CONFIRMED,
    ];

    /** Directory on the `public` disk that court photos live in. */
    private const PHOTO_DIRECTORY = 'courts';

    public function __construct(private readonly AuditTrailService $audit) {}

    /* ------------------------------------------------------------------ */
    /* Read                                                                */
    /* ------------------------------------------------------------------ */

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Court::class);

        $filters = $this->filters($request);

        $courts = Court::query()
            // `description` is a TEXT column nobody reads in a list — leaving it
            // out keeps the row payload small on a wide result set.
            ->select([
                'id', 'name', 'slug', 'code', 'photo_path',
                'is_active', 'sort_order', 'created_at',
            ])
            ->withCount([
                // The Court model's availableSlotsCount accessor picks this
                // alias up, so no row ever falls back to a per-row query.
                'slots as available_slots_count' => fn (Builder $q): Builder => $q->available()->upcoming(),
                'slots as total_slots_count',
                'bookings as bookings_count',
            ])
            ->search($filters['search'])
            ->when(
                $filters['status'] !== null,
                fn (Builder $q): Builder => $q->where('is_active', $filters['status'] === 'active'),
            )
            ->when(
                $filters['sort'] !== null,
                fn (Builder $q): Builder => $q->orderBy(self::SORTABLE[$filters['sort']], $filters['direction']),
                // No explicit sort: honour the operator's own display order.
                fn (Builder $q): Builder => $q->ordered(),
            )
            // Stable tiebreaker so pagination cannot repeat or skip a row.
            ->orderBy('id')
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (Court $court): array => $this->rowPayload($court));

        return Inertia::render('Admin/Courts/Index', [
            'courts' => $courts,
            'filters' => $filters,
            'stats' => $this->overview(),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    public function show(Court $court): Response
    {
        $this->authorize('view', $court);

        $court->loadCount([
            'slots as total_slots_count',
            'slots as available_slots_count' => fn (Builder $q): Builder => $q->available()->upcoming(),
            'slots as booked_slots_count' => fn (Builder $q): Builder => $q->where('status', CourtSlot::STATUS_BOOKED),
            'slots as blocked_slots_count' => fn (Builder $q): Builder => $q->where('status', CourtSlot::STATUS_BLOCKED),
            'bookings as total_bookings_count',
            'bookings as live_bookings_count' => fn (Builder $q): Builder => $q->whereIn('status', self::LIVE_BOOKING_STATUSES),
            'bookings as pending_bookings_count' => fn (Builder $q): Builder => $q->holding(),
            'bookings as confirmed_bookings_count' => fn (Builder $q): Builder => $q->revenueBearing(),
        ]);

        $revenue = (float) $court->bookings()->revenueBearing()->sum('amount');

        $recentBookings = $court->bookings()
            ->select([
                'id', 'code', 'court_id', 'court_slot_id', 'customer_name',
                'customer_phone', 'amount', 'status', 'created_at',
            ])
            // Cheap alongside the existing eager-load: a single count
            // subquery per row, no extra round trip. Lets the widget hint
            // "+N more" without hand-building the full multi-slot payload.
            ->withCount('slots')
            ->with(['slot:id,slot_date,start_time,end_time,status'])
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Booking $booking): array => [
                'id' => $booking->id,
                'code' => $booking->code,
                'customer_name' => $booking->customer_name,
                'customer_phone' => $booking->customer_phone,
                'amount' => (float) $booking->amount,
                'status' => $booking->status,
                'created_at' => $booking->created_at?->toIso8601String(),
                'slot' => $booking->slot === null ? null : [
                    'id' => $booking->slot->id,
                    'slot_date' => $booking->slot->slot_date?->toDateString(),
                    'time_range' => $booking->slot->time_range,
                    'status' => $booking->slot->status,
                ],
                'slot_count' => (int) $booking->slots_count,
            ])
            ->all();

        $this->audit->logView('Courts', sprintf("Viewed court '%s'.", $court->name), $court);

        return Inertia::render('Admin/Courts/Show', [
            'court' => $this->detailPayload($court),
            'stats' => [
                'total_slots' => (int) $court->total_slots_count,
                'available_slots' => (int) $court->available_slots_count,
                'booked_slots' => (int) $court->booked_slots_count,
                'blocked_slots' => (int) $court->blocked_slots_count,
                'total_bookings' => (int) $court->total_bookings_count,
                'live_bookings' => (int) $court->live_bookings_count,
                'pending_bookings' => (int) $court->pending_bookings_count,
                'confirmed_bookings' => (int) $court->confirmed_bookings_count,
                'revenue' => $revenue,
            ],
            'recentBookings' => $recentBookings,
            'canUpdate' => $this->userCan('update', $court),
            'canDelete' => $this->userCan('delete', $court),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Write                                                               */
    /* ------------------------------------------------------------------ */

    public function create(): Response
    {
        $this->authorize('create', Court::class);

        return Inertia::render('Admin/Courts/Form', [
            'court' => null,
            'nextSortOrder' => (int) (Court::query()->max('sort_order') ?? -1) + 1,
        ]);
    }

    public function edit(Court $court): Response
    {
        $this->authorize('update', $court);

        return Inertia::render('Admin/Courts/Form', [
            'court' => $this->detailPayload($court),
            'nextSortOrder' => (int) $court->sort_order,
        ]);
    }

    public function store(StoreCourtRequest $request): RedirectResponse
    {
        $this->authorize('create', Court::class);

        $data = $request->validated();
        $photoPath = $this->storePhoto($request->file('photo'));

        try {
            $court = DB::transaction(fn (): Court => Court::create([
                'name' => $data['name'],
                'code' => $data['code'],
                'description' => $data['description'] ?? null,
                'is_active' => (bool) $data['is_active'],
                'sort_order' => (int) $data['sort_order'],
                'photo_path' => $photoPath,
            ]));
        } catch (Throwable $e) {
            // The upload landed before the row did; do not leave it orphaned.
            $this->deletePhoto($photoPath);

            throw $e;
        }

        return redirect()
            ->route('admin.courts.show', $court)
            ->with('success', sprintf('Court "%s" was created.', $court->name));
    }

    public function update(UpdateCourtRequest $request, Court $court): RedirectResponse
    {
        $this->authorize('update', $court);

        $data = $request->validated();
        $previousPhoto = $court->photo_path;
        $uploaded = $this->storePhoto($request->file('photo'));

        // A new upload always wins; otherwise honour an explicit removal.
        $photoPath = match (true) {
            $uploaded !== null => $uploaded,
            (bool) ($data['remove_photo'] ?? false) => null,
            default => $previousPhoto,
        };

        try {
            DB::transaction(function () use ($court, $data, $photoPath): void {
                $court->fill([
                    'name' => $data['name'],
                    'code' => $data['code'],
                    'description' => $data['description'] ?? null,
                    'is_active' => (bool) $data['is_active'],
                    'sort_order' => (int) $data['sort_order'],
                    'photo_path' => $photoPath,
                ])->save();
            });
        } catch (Throwable $e) {
            $this->deletePhoto($uploaded);

            throw $e;
        }

        // Only once the row is safely written do we destroy the old file.
        if ($previousPhoto !== null && $previousPhoto !== $photoPath) {
            $this->deletePhoto($previousPhoto);
        }

        return redirect()
            ->route('admin.courts.show', $court)
            ->with('success', sprintf('Court "%s" was updated.', $court->name));
    }

    /**
     * Activate / deactivate. Recorded as its own audit action rather than a
     * generic `update`, because "who took this court off the site and when" is
     * a question operators actually ask.
     */
    public function toggleStatus(Court $court): RedirectResponse
    {
        $this->authorize('update', $court);

        $next = ! $court->is_active;

        // saveQuietly keeps the Auditable trait from writing a second, blander
        // "is active: yes -> no" row alongside the explicit entry below.
        $court->forceFill(['is_active' => $next])->saveQuietly();

        $this->audit->log(
            module: 'Courts',
            action: $next ? 'activate' : 'deactivate',
            description: sprintf(
                "%s court '%s' (%s).",
                $next ? 'Activated' : 'Deactivated',
                $court->name,
                $court->code,
            ),
            model: $court,
            oldValues: ['is_active' => ! $next],
            newValues: ['is_active' => $next],
        );

        return back()->with(
            'success',
            $next
                ? sprintf('"%s" is now active and visible on the booking site.', $court->name)
                : sprintf('"%s" is now inactive. Existing bookings are untouched.', $court->name),
        );
    }

    /**
     * Soft delete — but only once the court has nothing live attached to it.
     */
    public function destroy(Court $court): RedirectResponse
    {
        $this->authorize('delete', $court);

        $blocking = $this->liveBookingCount($court);

        if ($blocking > 0) {
            return back()->with('error', sprintf(
                '"%s" still has %d active or upcoming booking%s, so it cannot be deleted. '
                .'Deactivate it instead — that hides it from the booking site immediately '
                .'while those customers keep their reservations.',
                $court->name,
                $blocking,
                $blocking === 1 ? '' : 's',
            ));
        }

        $photoPath = $court->photo_path;

        DB::transaction(function () use ($court): void {
            if ($court->photo_path !== null) {
                // Quiet: the delete entry below already tells the whole story.
                $court->forceFill(['photo_path' => null])->saveQuietly();
            }

            $court->delete();
        });

        $this->deletePhoto($photoPath);

        return back()->with('success', sprintf('Court "%s" was deleted.', $court->name));
    }

    /* ------------------------------------------------------------------ */
    /* Query helpers                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Sanitised table state. Every value is echoed back to the page so the
     * filter controls rehydrate from the URL after `->withQueryString()`.
     *
     * @return array{search: string|null, status: string|null, sort: string|null, direction: string}
     */
    private function filters(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $sort = (string) $request->query('sort', '');

        return [
            'search' => $search === '' ? null : $search,
            'status' => in_array($status, ['active', 'inactive'], true) ? $status : null,
            'sort' => array_key_exists($sort, self::SORTABLE) ? $sort : null,
            'direction' => $request->query('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    private function perPage(Request $request): int
    {
        $default = (int) config('booking.per_page', 25);
        $requested = (int) $request->query('per_page', (string) $default);

        return in_array($requested, self::PER_PAGE_OPTIONS, true) ? $requested : $default;
    }

    /**
     * Headline numbers above the table. Two aggregates, no per-row work.
     *
     * @return array{total: int, active: int, inactive: int, available_slots: int}
     */
    private function overview(): array
    {
        /** @var object{total: int|string, active: int|string|null}|null $row */
        $row = Court::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active')
            ->first();

        $total = (int) ($row->total ?? 0);
        $active = (int) ($row->active ?? 0);

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => max(0, $total - $active),
            'available_slots' => CourtSlot::query()->available()->upcoming()->count(),
        ];
    }

    /**
     * Bookings that make deleting the court unsafe: anything still awaiting
     * money or already paid for, plus any non-terminal booking whose slot has
     * not happened yet.
     */
    private function liveBookingCount(Court $court): int
    {
        return $court->bookings()
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('status', self::LIVE_BOOKING_STATUSES)
                    ->orWhere(function (Builder $inner): void {
                        $inner
                            ->whereNotIn('status', [
                                Booking::STATUS_REJECTED,
                                Booking::STATUS_EXPIRED,
                                Booking::STATUS_CANCELLED,
                            ])
                            ->whereHas('slot', fn (Builder $slot): Builder => $slot->upcoming());
                    });
            })
            ->count();
    }

    /* ------------------------------------------------------------------ */
    /* Payloads                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function rowPayload(Court $court): array
    {
        return [
            'id' => $court->id,
            'name' => $court->name,
            'slug' => $court->slug,
            'code' => $court->code,
            'photo_url' => $this->photoUrl($court->photo_path),
            'is_active' => (bool) $court->is_active,
            'sort_order' => (int) $court->sort_order,
            'available_slots_count' => (int) $court->available_slots_count,
            'total_slots_count' => (int) $court->total_slots_count,
            'bookings_count' => (int) $court->bookings_count,
            'created_at' => $court->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(Court $court): array
    {
        return [
            'id' => $court->id,
            'name' => $court->name,
            'slug' => $court->slug,
            'code' => $court->code,
            'description' => $court->description,
            'photo_url' => $this->photoUrl($court->photo_path),
            'has_photo' => $court->photo_path !== null,
            'is_active' => (bool) $court->is_active,
            'sort_order' => (int) $court->sort_order,
            'created_at' => $court->created_at?->toIso8601String(),
            'updated_at' => $court->updated_at?->toIso8601String(),
        ];
    }

    private function userCan(string $ability, Court $court): bool
    {
        return request()->user()?->can($ability, $court) ?? false;
    }

    /* ------------------------------------------------------------------ */
    /* Photo storage                                                       */
    /* ------------------------------------------------------------------ */

    private function storePhoto(?UploadedFile $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $path = $file->store(self::PHOTO_DIRECTORY, 'public');

        return $path === false ? null : $path;
    }

    private function deletePhoto(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $disk = Storage::disk('public');

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }

    private function photoUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
