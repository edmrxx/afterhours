<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Slot\BulkDestroySlotsRequest;
use App\Http\Requests\Slot\GenerateSlotsRequest;
use App\Http\Requests\Slot\RepriceSlotsRequest;
use App\Http\Requests\Slot\StoreSlotRequest;
use App\Http\Requests\Slot\UpdateSlotRequest;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\Setting;
use App\Services\AuditTrailService;
use App\Services\PricingService;
use App\Services\SettingsService;
use App\Services\SlotGeneratorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The scheduling screen: a court, a date window, and everything on it.
 *
 * Slots are fully admin defined — After Hours has no fixed system-wide interval — so
 * this controller has to serve both the surgical case (add one 90-minute slot
 * next Tuesday) and the bulk case (lay down three months of weekday hours in
 * one action). The bulk work lives in SlotGeneratorService; what stays here is
 * the read model and the lifecycle guards.
 *
 * The guards all say the same thing in different words: a slot a customer is
 * currently sitting on — `held` or `booked` — is not the admin's to edit, block
 * or delete. Silently deleting one is a refund and an angry phone call.
 */
class CourtSlotController extends Controller
{
    /** Widest date window the grid will render at once. */
    private const MAX_RANGE_DAYS = 31;

    /** Page sizes offered by the grid. The screen always sends one explicitly. */
    private const PER_PAGE_OPTIONS = [50, 100, 200, 400];

    private const DEFAULT_PER_PAGE = 100;

    public function __construct(
        private readonly SlotGeneratorService $generator,
        private readonly PricingService $pricing,
        private readonly AuditTrailService $audit,
        private readonly SettingsService $settings,
    ) {}

    /* --------------------------------------------------------------------- */
    /* Read                                                                   */
    /* --------------------------------------------------------------------- */

    public function index(Request $request): Response
    {
        // Pricing is global now, so the court rows carry no rate of their own —
        // the generator modal reads the club-wide table passed as `pricing`.
        $courts = Court::query()
            ->ordered()
            ->get(['id', 'name', 'code', 'slug', 'is_active']);

        $courtId = $this->resolveCourtId($request, $courts);
        [$from, $to] = $this->resolveRange($request);

        $status = $this->resolveStatus($request);
        $search = trim((string) $request->query('search', ''));
        $sort = $this->resolveSort($request);
        $perPage = $this->resolvePerPage($request);

        // `court_id => 0` when the system has no courts yet: the query stays
        // shaped the same and simply returns nothing, so the page can render
        // its empty state without a second code path.
        $scoped = CourtSlot::query()
            ->where('court_id', $courtId ?? 0)
            ->betweenDates($from, $to);

        $slots = (clone $scoped)
            ->with([
                'court:id,name,code,slug',
                'heldBooking:id,code,status,customer_name,customer_phone,hold_expires_at',
            ])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $this->applySearch($query, $search))
            ->orderBy($sort['column'], $sort['direction'])
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        $slots->through(fn (CourtSlot $slot): array => $this->presentSlot($slot));

        return Inertia::render('Admin/Slots/Index', [
            'courts' => $courts->map(fn (Court $court): array => [
                'id' => $court->id,
                'name' => $court->name,
                'code' => $court->code,
                'is_active' => $court->is_active,
            ])->values(),
            // The club-wide rate table, so the generator/add modals can show the
            // rates they will stamp without any per-court lookup.
            'pricing' => $this->pricing->bounds(),
            'slots' => $slots,
            'days' => $this->calendarDays($from, $to),
            'stats' => $this->statusCounts(clone $scoped),
            'filters' => [
                'court' => $courtId,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'status' => $status,
                'search' => $search,
                'sort' => $sort['key'],
                'direction' => $sort['direction'],
                'per_page' => $perPage,
            ],
            'options' => [
                'per_page' => self::PER_PAGE_OPTIONS,
                'max_range_days' => self::MAX_RANGE_DAYS,
                'max_generate_slots' => (int) config('booking.max_generate_slots', 2000),
                // The club-wide operating window (Admin > Settings > Company) seeds
                // the generator's default opening/closing time, so a fresh run
                // already matches the hours the operator set instead of a hardcoded
                // guess. The generator still lets them override per run.
                'operating_opening_time' => (string) ($this->settings->get(Setting::GROUP_COMPANY, 'operating_opening_time') ?: '07:00'),
                'operating_closing_time' => (string) ($this->settings->get(Setting::GROUP_COMPANY, 'operating_closing_time') ?: '02:00'),
            ],
        ]);
    }

    /* --------------------------------------------------------------------- */
    /* Single slot lifecycle                                                  */
    /* --------------------------------------------------------------------- */

    public function store(StoreSlotRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $slot = CourtSlot::create([
            'court_id' => (int) $data['court_id'],
            'slot_date' => $data['slot_date'],
            'start_time' => $data['start_time'].':00',
            'end_time' => $data['end_time'].':00',
            'price' => round((float) $data['price'], 2),
            'status' => $data['status'] ?? CourtSlot::STATUS_AVAILABLE,
        ]);

        return back()->with('success', sprintf(
            'Slot added — %s on %s.',
            $slot->time_range,
            $slot->slot_date->format('D, j M Y'),
        ));
    }

    public function update(UpdateSlotRequest $request, CourtSlot $slot): RedirectResponse
    {
        if ($refusal = $this->refuseIfClaimed($slot, 'edited')) {
            return $refusal;
        }

        $data = $request->validated();

        $slot->update([
            'court_id' => (int) $data['court_id'],
            'slot_date' => $data['slot_date'],
            'start_time' => $data['start_time'].':00',
            'end_time' => $data['end_time'].':00',
            'price' => round((float) $data['price'], 2),
            'status' => $data['status'] ?? $slot->status,
        ]);

        return back()->with('success', sprintf(
            'Slot updated — %s on %s.',
            $slot->time_range,
            $slot->slot_date->format('D, j M Y'),
        ));
    }

    /**
     * Take a slot off the market, or put it back. Never applies to a slot a
     * customer already holds: blocking it would strand a live booking.
     */
    public function toggleBlock(CourtSlot $slot): RedirectResponse
    {
        if ($refusal = $this->refuseIfClaimed($slot, 'blocked or unblocked')) {
            return $refusal;
        }

        $blocking = $slot->status !== CourtSlot::STATUS_BLOCKED;

        $slot->update([
            'status' => $blocking ? CourtSlot::STATUS_BLOCKED : CourtSlot::STATUS_AVAILABLE,
        ]);

        return back()->with('success', sprintf(
            '%s on %s is now %s.',
            $slot->time_range,
            $slot->slot_date->format('D, j M Y'),
            $blocking ? 'blocked and off the public schedule' : 'available for booking',
        ));
    }

    /**
     * Delete one slot.
     *
     * The parameter is taken raw rather than by implicit binding on purpose.
     * `DELETE /admin/slots/{slot}` is registered ahead of
     * `DELETE /admin/slots/bulk` in routes/web.php — which is the contract and
     * must not be reordered — so the wildcard matches "bulk" first and the
     * batch endpoint would 404 on a missing model. Resolving the key here lets
     * that one URL be handed to its intended handler. Both routes carry the
     * identical `permission:slots.delete` middleware, so nothing is bypassed.
     */
    public function destroy(string $slot): RedirectResponse
    {
        if ($slot === 'bulk') {
            // Resolving the form request through the container runs its
            // authorisation and validation exactly as method injection would.
            return $this->bulkDestroy(app(BulkDestroySlotsRequest::class));
        }

        $model = CourtSlot::query()->findOrFail((int) $slot);

        if ($refusal = $this->refuseIfClaimed($model, 'deleted')) {
            return $refusal;
        }

        $label = sprintf('%s on %s', $model->time_range, $model->slot_date->format('D, j M Y'));

        // Both booking foreign keys are restrictOnDelete: a slot that ever
        // carried a reservation — even one since cancelled or expired — cannot
        // be deleted at all. Refuse cleanly rather than surface a 500.
        if ($model->hasBookingHistory()) {
            return back()->with('error', sprintf(
                'Slot %s cannot be deleted because a booking (even a cancelled or expired one) references it. Block it instead to take it off the market.',
                $label,
            ));
        }

        // Delete through the same predicates rather than $model->delete(): the
        // guards above are a snapshot, and a reserve() could claim this slot (or
        // write its booking_slots row) in the gap before the write. Folding
        // status + withoutBookingHistory into the DELETE makes it self-checking,
        // so a lost race deletes nothing — and returns a clean message — instead
        // of hitting the restrictOnDelete FK with a 500.
        $deleted = CourtSlot::query()
            ->whereKey($model->getKey())
            ->whereIn('status', [CourtSlot::STATUS_AVAILABLE, CourtSlot::STATUS_BLOCKED])
            ->withoutBookingHistory()
            ->delete();

        if ($deleted === 0) {
            return back()->with('error', sprintf(
                'Slot %s could not be deleted — it was just claimed or referenced by a booking. Refresh and try again.',
                $label,
            ));
        }

        // Audit explicitly: a query-builder delete (used above for the race-safe
        // predicates) fires no Eloquent model event, so — unlike $model->delete()
        // — the Auditable trait records nothing on its own. bulkDestroy() logs the
        // same way for the same reason.
        $this->audit->log(
            module: 'Slots',
            action: 'delete',
            description: 'Deleted court slot — '.$label.'.',
            oldValues: [
                'id' => $model->getKey(),
                'court_id' => $model->court_id,
                'slot_date' => $model->slot_date->toDateString(),
                'start_time' => (string) $model->start_time,
                'status' => $model->status,
            ],
        );

        return back()->with('success', 'Slot deleted — '.$label.'.');
    }

    /**
     * Multi-select delete. Held and booked slots are skipped rather than
     * failing the whole batch, and the operator is told exactly how many
     * survived and why.
     */
    public function bulkDestroy(BulkDestroySlotsRequest $request): RedirectResponse
    {
        $slots = CourtSlot::query()
            ->whereIn('id', $request->slotIds())
            ->get(['id', 'court_id', 'slot_date', 'start_time', 'end_time', 'status']);

        if ($slots->isEmpty()) {
            return back()->with('warning', 'Those slots have already been removed.');
        }

        $deletable = $slots->whereIn('status', [
            CourtSlot::STATUS_AVAILABLE,
            CourtSlot::STATUS_BLOCKED,
        ]);

        $held = $slots->where('status', CourtSlot::STATUS_HELD)->count();
        $booked = $slots->where('status', CourtSlot::STATUS_BOOKED)->count();
        $skipped = $held + $booked;

        if ($deletable->isEmpty()) {
            return back()->with('error', sprintf(
                'Nothing was deleted. %s cannot be removed because %s.',
                $this->plural($skipped, 'slot'),
                $this->claimedReason($held, $booked),
            ));
        }

        // Booking history pins a slot forever (both booking FKs are
        // restrictOnDelete), so the batch is narrowed to rows the database will
        // actually let go of; the pinned remainder is reported, not attempted —
        // one pinned row would otherwise abort the whole statement with a
        // foreign key violation.
        $ids = CourtSlot::query()
            ->whereIn('id', $deletable->pluck('id')->all())
            ->withoutBookingHistory()
            ->pluck('id')
            ->all();

        $pinned = $deletable->count() - count($ids);

        if ($ids === []) {
            return back()->with('error', sprintf(
                'Nothing was deleted. %s kept — booking history (even cancelled or expired bookings) blocks deletion; block them instead%s.',
                $this->plural($pinned, 'slot'),
                $skipped > 0 ? sprintf('; %s skipped because %s', $this->plural($skipped, 'slot'), $this->claimedReason($held, $booked)) : '',
            ));
        }

        // Self-guarding delete: the pluck above is a snapshot, so fold the same
        // predicates into the DELETE. A slot claimed or newly-referenced by a
        // reserve() in the gap is then silently left behind (0 of its rows match)
        // rather than aborting the whole statement on the restrictOnDelete FK.
        // The affected-row count is the real number removed.
        $deleted = CourtSlot::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [CourtSlot::STATUS_AVAILABLE, CourtSlot::STATUS_BLOCKED])
            ->withoutBookingHistory()
            ->delete();

        if ($deleted === 0) {
            return back()->with('warning', 'Nothing was deleted — the selected slots were just claimed or referenced by a booking. Refresh and try again.');
        }

        $this->audit->log(
            module: 'Slots',
            action: 'delete',
            description: sprintf(
                'Bulk deleted %s%s%s.',
                $this->plural($deleted, 'court slot'),
                $skipped > 0 ? sprintf(' (%d skipped as held or booked)', $skipped) : '',
                $pinned > 0 ? sprintf(' (%d kept — booking history)', $pinned) : '',
            ),
            oldValues: ['ids' => $ids],
        );

        $message = $this->plural($deleted, 'slot').' deleted.';

        if ($skipped === 0 && $pinned === 0) {
            return back()->with('success', $message);
        }

        $reasons = [];

        if ($skipped > 0) {
            $reasons[] = sprintf('%s skipped because %s', $this->plural($skipped, 'slot'), $this->claimedReason($held, $booked));
        }

        if ($pinned > 0) {
            $reasons[] = sprintf('%s kept — booking history blocks deletion; block those instead', $this->plural($pinned, 'slot'));
        }

        return back()->with('warning', sprintf('%s %s.', $message, implode('; ', $reasons)));
    }

    /* --------------------------------------------------------------------- */
    /* Bulk generator                                                         */
    /* --------------------------------------------------------------------- */

    /**
     * Dry run. Returns JSON rather than an Inertia response so the generator
     * modal can re-cost the pattern on every keystroke without a page visit.
     */
    public function preview(GenerateSlotsRequest $request): JsonResponse
    {
        $court = Court::query()->findOrFail((int) $request->validated('court_id'));

        return response()->json($this->generator->preview($court, $request->validated()));
    }

    public function generate(GenerateSlotsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $court = Court::query()->findOrFail((int) $data['court_id']);

        $summary = $this->generator->generate($court, $data);

        $this->audit->log(
            module: 'Slots',
            action: 'create',
            description: sprintf(
                'Generated %d slots for %s between %s and %s (%d already existed).',
                $summary['created'],
                $court->name,
                $data['start_date'],
                $data['end_date'],
                $summary['skipped'],
            ),
            model: $court,
            newValues: $summary + ['court_id' => $court->id],
        );

        $from = Carbon::parse($data['start_date']);
        $to = Carbon::parse($data['end_date']);

        $redirect = redirect()->route('admin.slots.index', [
            'court' => $court->id,
            'from' => $from->toDateString(),
            'to' => $from->copy()->addDays(self::MAX_RANGE_DAYS - 1)->min($to)->toDateString(),
        ]);

        if ($summary['created'] === 0) {
            return $redirect->with('info', sprintf(
                'Nothing to do — all %s in that pattern already exist.',
                $this->plural($summary['total'], 'slot'),
            ));
        }

        if ($summary['skipped'] > 0) {
            return $redirect->with('success', sprintf(
                '%s created for %s. %s already existed and were left untouched.',
                $this->plural($summary['created'], 'slot'),
                $court->name,
                $this->plural($summary['skipped'], 'slot'),
            ));
        }

        return $redirect->with('success', sprintf(
            '%s created for %s.',
            $this->plural($summary['created'], 'slot'),
            $court->name,
        ));
    }

    /* --------------------------------------------------------------------- */
    /* On-demand reprice                                                      */
    /* --------------------------------------------------------------------- */

    /**
     * Push a court's current effective rates onto its already generated,
     * still-`available` slots. Deliberate and admin-triggered only — nothing
     * about editing a court's rates causes this on its own, and nothing here
     * ever touches a held, booked or blocked slot.
     */
    public function reprice(RepriceSlotsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $court = Court::query()->findOrFail((int) $data['court_id']);

        $summary = $this->generator->reprice($court, $data['from_date'] ?? null, $data['to_date'] ?? null);

        $this->audit->log(
            module: 'Slots',
            action: 'update',
            description: $this->repriceDescription($court, $summary),
            model: $court,
            newValues: $summary,
        );

        if ($summary['total'] === 0) {
            return back()->with('info', sprintf(
                'Nothing to reprice — every available slot for %s already matches the current rates.',
                $court->name,
            ));
        }

        return back()->with('success', sprintf(
            '%s repriced to the current rates on %s.',
            $this->plural($summary['total'], 'slot'),
            $court->name,
        ));
    }

    /**
     * The audit sentence: what court, what window, and the per-tier tally
     * that actually changed — the same shape the client asked for verbatim.
     *
     * @param  array{from: string, to: ?string, total: int, breakdown: list<array{tier: string, rate: float, count: int, ...}>}  $summary
     */
    private function repriceDescription(Court $court, array $summary): string
    {
        $tiers = collect($summary['breakdown'])
            ->map(fn (array $row): string => sprintf(
                '%s ₱%s x%s',
                PricingService::label((string) $row['tier']),
                number_format((float) $row['rate'], 2),
                number_format($row['count']),
            ))
            ->implode(', ');

        return sprintf(
            'Repriced %s available slots on %s to current rates (%s) from %s onward%s.',
            number_format($summary['total']),
            $court->name,
            $tiers,
            $summary['from'],
            $summary['to'] !== null ? ' through '.$summary['to'] : '',
        );
    }

    /* --------------------------------------------------------------------- */
    /* Sync to operating hours                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Reshape every court's future schedule to the club's operating hours
     * (Admin > Settings > Company). Deliberate and admin-triggered only: adds the
     * hourly slots for hours that are now open, removes the `available` slots for
     * hours that are now closed, and never touches a held, booked or blocked slot
     * — see SlotGeneratorService::syncToOperatingHours().
     */
    public function syncOperatingHours(): RedirectResponse
    {
        $opening = (string) ($this->settings->get(Setting::GROUP_COMPANY, 'operating_opening_time') ?: '07:00');
        $closing = (string) ($this->settings->get(Setting::GROUP_COMPANY, 'operating_closing_time') ?: '02:00');

        try {
            $summary = $this->generator->syncToOperatingHours($opening, $closing);
        } catch (ValidationException $e) {
            // A window too short to hold a single slot — refuse rather than reshape
            // to an empty schedule. Should be unreachable now the settings form
            // enforces a minimum window, but the guard makes it safe regardless.
            return back()->with('error', $e->validator->errors()->first() ?: 'The operating hours are too short to sync.');
        }

        if ($summary['courts'] === 0) {
            return back()->with('info', 'There are no courts to sync yet.');
        }

        $this->audit->log(
            module: 'Slots',
            action: 'update',
            description: sprintf(
                'Synced %d of %d court(s) to operating hours %s–%s: added %s, removed %s available slot(s)%s%s.',
                $summary['courts_changed'],
                $summary['courts'],
                $opening,
                $closing,
                number_format($summary['added']),
                number_format($summary['removed']),
                $summary['kept_history'] > 0
                    ? sprintf(', blocked %s off-hours slot(s) that carry booking history', number_format($summary['kept_history']))
                    : '',
                $summary['skipped_dates'] > 0
                    // A date is skipped by an off-hours held/booked slot OR by a
                    // blocked slot overlapping the window — not always a booking.
                    ? sprintf(' (%s date(s) with off-hours bookings or blocks left untouched)', number_format($summary['skipped_dates']))
                    : '',
            ),
            newValues: $summary,
        );

        $nothingChanged = $summary['added'] === 0 && $summary['removed'] === 0 && $summary['kept_history'] === 0;

        // Even when nothing moved, a blocked slot sitting on an in-window hour is
        // a bookable hour the public can't see — surface it so "nothing changed"
        // never quietly hides one (e.g. after hours were widened back).
        $blockedNote = $summary['blocked_in_window'] > 0
            ? sprintf(' %s blocked slot(s) fall within your operating hours — review them on the Slots screen and unblock any that should sell.', number_format($summary['blocked_in_window']))
            : '';

        if ($nothingChanged) {
            $base = $summary['skipped_dates'] > 0
                ? 'Nothing changed — the only off-hours slots are on dates left untouched.'
                : 'Every court already matches the operating hours — nothing to sync.';

            return back()->with($blockedNote !== '' ? 'warning' : 'info', $base.$blockedNote);
        }

        return back()->with('success', sprintf(
            'Synced to operating hours across %d court(s): added %s, removed %s.%s%s%s',
            $summary['courts_changed'],
            $this->plural($summary['added'], 'slot'),
            $this->plural($summary['removed'], 'slot'),
            $summary['kept_history'] > 0
                // Deleting them is impossible — booking history pins the row —
                // so they were taken off the market instead.
                ? sprintf(' %s off-hours slot(s) with booking history were blocked instead of removed.', number_format($summary['kept_history']))
                : '',
            $summary['skipped_dates'] > 0
                ? sprintf(' %s date(s) with off-hours bookings or blocks were left as they are.', number_format($summary['skipped_dates']))
                : '',
            $blockedNote,
        ));
    }

    /* --------------------------------------------------------------------- */
    /* Guards                                                                 */
    /* --------------------------------------------------------------------- */

    /**
     * One refusal path for edit / block / delete so the wording — and the
     * rule — can never drift between them.
     */
    private function refuseIfClaimed(CourtSlot $slot, string $verb): ?RedirectResponse
    {
        if (! in_array($slot->status, [CourtSlot::STATUS_HELD, CourtSlot::STATUS_BOOKED], true)) {
            return null;
        }

        $reason = $slot->status === CourtSlot::STATUS_HELD
            ? 'a customer is currently holding it while they pay'
            : 'it belongs to a confirmed booking';

        return back()->with('error', sprintf(
            'That slot cannot be %s — %s. Cancel or reject the booking first.',
            $verb,
            $reason,
        ));
    }

    /* --------------------------------------------------------------------- */
    /* Query helpers                                                          */
    /* --------------------------------------------------------------------- */

    /** @param  Builder<CourtSlot>  $query */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function ($outer) use ($like): void {
            $outer->where('start_time', 'like', $like)
                ->orWhere('end_time', 'like', $like)
                ->orWhereHas('heldBooking', function ($booking) use ($like): void {
                    $booking->where('code', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhere('customer_phone', 'like', $like);
                });
        });
    }

    /** @param  Collection<int, Court>  $courts */
    private function resolveCourtId(Request $request, Collection $courts): ?int
    {
        $requested = $request->integer('court');

        if ($requested > 0 && $courts->contains('id', $requested)) {
            return $requested;
        }

        // Default to the first court an operator would actually be scheduling.
        $default = $courts->firstWhere('is_active', true) ?? $courts->first();

        return $default?->id;
    }

    /**
     * The visible window, clamped to MAX_RANGE_DAYS. A grid of a hundred day
     * columns is neither readable nor cheap, so the bound is real, not advisory.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(Request $request): array
    {
        $from = $this->parseDate($request->query('from')) ?? Carbon::today();
        $to = $this->parseDate($request->query('to')) ?? $from->copy()->addDays(6);

        if ($to->lessThan($from)) {
            $to = $from->copy()->addDays(6);
        }

        $maxTo = $from->copy()->addDays(self::MAX_RANGE_DAYS - 1);

        return [$from, $to->greaterThan($maxTo) ? $maxTo : $to];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($value))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveStatus(Request $request): ?string
    {
        $status = (string) $request->query('status', '');

        return in_array($status, CourtSlot::STATUSES, true) ? $status : null;
    }

    /** @return array{key: string, column: string, direction: string} */
    private function resolveSort(Request $request): array
    {
        $sortable = [
            'slot_date' => 'slot_date',
            'start_time' => 'start_time',
            'price' => 'price',
            'status' => 'status',
        ];

        $key = (string) $request->query('sort', 'slot_date');
        $key = array_key_exists($key, $sortable) ? $key : 'slot_date';

        return [
            'key' => $key,
            'column' => $sortable[$key],
            'direction' => $request->query('direction') === 'desc' ? 'desc' : 'asc',
        ];
    }

    private function resolvePerPage(Request $request): int
    {
        $perPage = $request->integer('per_page');

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
    }

    /* --------------------------------------------------------------------- */
    /* Presentation                                                           */
    /* --------------------------------------------------------------------- */

    /**
     * Slots are shipped as plain arrays rather than serialised models: the JS
     * grid needs `slot_date` as a bare "YYYY-MM-DD" to group by, and times as
     * "HH:MM" to feed straight back into `<input type="time">`.
     *
     * @return array<string, mixed>
     */
    private function presentSlot(CourtSlot $slot): array
    {
        $booking = $slot->heldBooking;

        return [
            'id' => $slot->id,
            'court_id' => $slot->court_id,
            'court' => $slot->court === null ? null : [
                'id' => $slot->court->id,
                'name' => $slot->court->name,
                'code' => $slot->court->code,
            ],
            'slot_date' => $slot->slot_date->toDateString(),
            'start_time' => $this->clock($slot, 'start_time'),
            'end_time' => $this->clock($slot, 'end_time'),
            'time_range' => $slot->time_range,
            'duration_minutes' => $slot->duration_minutes,
            'price' => (string) $slot->price,
            'status' => $slot->status,
            'is_past' => $slot->hasStarted(),
            'is_locked' => in_array($slot->status, [CourtSlot::STATUS_HELD, CourtSlot::STATUS_BOOKED], true),
            'held_booking' => $booking === null ? null : [
                'id' => $booking->id,
                'code' => $booking->code,
                'status' => $booking->status,
                'customer_name' => $booking->customer_name,
                'customer_phone' => $booking->customer_phone,
                'hold_expires_at' => $booking->hold_expires_at?->toIso8601String(),
            ],
        ];
    }

    /** Raw TIME column trimmed to the "HH:MM" an `<input type="time">` expects. */
    private function clock(CourtSlot $slot, string $key): string
    {
        $raw = (string) ($slot->getAttributes()[$key] ?? '00:00:00');

        return substr($raw, 0, 5);
    }

    /**
     * Every date in the window, so the grid can render an empty Wednesday
     * instead of quietly closing the gap.
     *
     * @return list<array<string, mixed>>
     */
    private function calendarDays(Carbon $from, Carbon $to): array
    {
        $days = [];
        $cursor = $from->copy();
        $today = Carbon::today();

        while ($cursor->lessThanOrEqualTo($to)) {
            $days[] = [
                'date' => $cursor->toDateString(),
                'weekday' => $cursor->format('D'),
                'day' => $cursor->format('j'),
                'month' => $cursor->format('M'),
                'label' => $cursor->format('D, j M'),
                'is_today' => $cursor->isSameDay($today),
                'is_past' => $cursor->lessThan($today),
                'is_weekend' => $cursor->isWeekend(),
            ];

            $cursor->addDay();
        }

        return $days;
    }

    /**
     * Status totals for the whole window — not just the current page — so the
     * summary tiles describe the schedule rather than the pagination.
     *
     * @param  Builder<CourtSlot>  $query
     * @return array<string, int>
     */
    private function statusCounts(Builder $query): array
    {
        $counts = $query->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $stats = ['total' => 0];

        foreach (CourtSlot::STATUSES as $status) {
            $stats[$status] = (int) ($counts[$status] ?? 0);
            $stats['total'] += $stats[$status];
        }

        return $stats;
    }

    private function plural(int $count, string $noun): string
    {
        return number_format($count).' '.($count === 1 ? $noun : $noun.'s');
    }

    private function claimedReason(int $held, int $booked): string
    {
        return match (true) {
            $held > 0 && $booked > 0 => 'they are held or booked',
            $booked > 0 => 'they belong to confirmed bookings',
            default => 'customers are holding them while they pay',
        };
    }
}
