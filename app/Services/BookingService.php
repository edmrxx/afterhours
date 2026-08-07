<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use App\Notifications\BookingRejected;
use App\Notifications\BookingReserved;
use App\Notifications\NewBookingSubmitted;
use App\Notifications\OwnerBookingAlert;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The booking state machine — the single place a booking or a slot may change.
 *
 * Concurrency contract
 * --------------------
 * Two visitors clicking the same 6pm slot at the same millisecond is the
 * failure mode this class exists to prevent. Every transition that touches a
 * slot therefore follows the same shape:
 *
 *     DB::transaction(fn () => CourtSlot::whereKey($id)->lockForUpdate()->first())
 *
 * and then **re-reads the status from the locked row**. Checking availability
 * before taking the lock is the classic race: both requests see `available`,
 * both write `held`, one customer loses their money. The check must happen on
 * the row we already own.
 *
 * Idempotency contract
 * --------------------
 * Every transition tolerates being called twice. Confirming an already
 * confirmed booking returns it untouched rather than re-stamping timestamps
 * and re-sending mail — double-clicked buttons and retried queue jobs are
 * normal traffic, not exceptions.
 *
 * Side-effect contract
 * --------------------
 * Notifications are dispatched only after the transaction has committed, and
 * every dispatch is wrapped: a dead SMTP host or an unreachable SMS gateway
 * must never roll back a confirmed booking.
 */
class BookingService
{
    /* Booking statuses — mirror of the enum in the migration. */
    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const PENDING_VERIFICATION = 'pending_verification';

    public const CONFIRMED = 'confirmed';

    public const COMPLETED = 'completed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Statuses whose slot is still only on hold. */
    public const HOLD_STATUSES = [self::AWAITING_PAYMENT, self::PENDING_VERIFICATION];

    /* Slot statuses. */
    public const SLOT_AVAILABLE = 'available';

    public const SLOT_HELD = 'held';

    public const SLOT_BOOKED = 'booked';

    public const SLOT_BLOCKED = 'blocked';

    /**
     * How the desk wants a manually keyed booking to land.
     *
     * `confirmed` is money already settled — in person, or an arrangement the
     * owner has personally vouched for — so the court is sold outright.
     * `reserved` holds the court for someone who has not paid yet: the same
     * shape as a guest's hold, with one deliberate difference — no expiry
     * clock, because it was arranged with a human, not with a checkout timer.
     */
    public const MANUAL_MODE_CONFIRMED = 'confirmed';

    public const MANUAL_MODE_RESERVED = 'reserved';

    /** @var list<string> */
    public const MANUAL_MODES = [self::MANUAL_MODE_CONFIRMED, self::MANUAL_MODE_RESERVED];

    public function __construct(
        private readonly AuditTrailService $audit,
        private readonly SettingsService $settings,
    ) {}

    /* ===================================================================== */
    /* 1. reserve() — guest claims a slot                                     */
    /* ===================================================================== */

    /**
     * Place a hold on one or more slots — one or several, possibly across
     * different courts and non-contiguous — and open a single booking in
     * `awaiting_payment`.
     *
     * @param  array<int, int|string>  $slotIds  every court_slot id being claimed
     * @param  array<string, mixed>  $customer  name / phone / email / notes
     *
     * @throws BookingException when any slot is gone, blocked, or in the past
     */
    public function reserve(array $slotIds, array $customer): Booking
    {
        $details = $this->normaliseCustomer($customer);

        // Dedupe and sort ascending BEFORE opening the transaction. This
        // deterministic ascending lock order is the single most important
        // property that prevents two customers who each request overlapping
        // multi-slot sets from deadlocking each other. The rest of this
        // class already follows a documented lock-ordering discipline (the
        // booking row is always locked before its slot row — see
        // confirm()/reject()/cancel()/expire() below); this extends that
        // same discipline to sets of slot rows, which must always be locked
        // in one fixed order relative to each other — never in whatever
        // order the customer happened to click them in the browser.
        $ids = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $slotIds,
        )));
        sort($ids);

        if ($ids === []) {
            throw BookingException::slotUnavailable();
        }

        $booking = DB::transaction(function () use ($ids, $details): Booking {
            return AuditTrailService::withoutAuditing(function () use ($ids, $details): Booking {
                // Take every row lock FIRST, in one query, in the fixed
                // ascending-id order established above. Everything decided
                // below is decided about rows no other transaction can be
                // looking at.
                $locked = CourtSlot::query()
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($locked->count() !== count($ids)) {
                    throw BookingException::slotUnavailable();
                }

                // Re-read the status from the locked rows — this is the whole
                // point. Checking availability before taking the lock is the
                // classic race; the check must happen on rows we already own.
                //
                // ALL-OR-NOTHING is the single most important correctness
                // property of this method: a customer who picks 3 open times
                // must never end up with only 1 or 2 actually held because
                // the 3rd was already taken by someone else. Throwing from
                // inside this loop aborts the one transaction wrapping every
                // write below, so a failure on any single slot guarantees
                // none of the requested slots end up held.
                foreach ($locked as $candidate) {
                    $status = $this->statusOf($candidate, 'status');

                    if ($status === self::SLOT_BLOCKED) {
                        throw BookingException::slotBlocked();
                    }

                    if ($status !== self::SLOT_AVAILABLE) {
                        throw BookingException::slotUnavailable();
                    }

                    if ($this->slotStartsAt($candidate)?->isPast() === true) {
                        throw BookingException::slotInPast();
                    }
                }

                $holdMinutes = (int) ($this->settings->get(Setting::GROUP_SYSTEM, 'booking_hold_minutes') ?: config('booking.hold_minutes', 30));

                // The "primary" slot: earliest by (slot_date, start_time), NOT
                // by id — a customer may click a later id before an earlier
                // one. Now that a booking may span more than one court, its
                // court_id/court_slot_id resolve to that earliest slot's court:
                // a backward-compatible representative, not the whole story, so
                // every existing join/sort/eager-load elsewhere in the app that
                // reads court_slot_id or slot()/courtSlot() keeps describing the
                // right thing unchanged. The full cross-court set lives in
                // booking_slots / slots().
                $chronological = $locked->sortBy([
                    ['slot_date', 'asc'],
                    ['start_time', 'asc'],
                ])->values();
                $primary = $chronological->first();

                // Price is copied, never referenced: a later price change
                // must not silently alter what this customer owes. For a
                // multi-slot booking that invariant extends naturally to a
                // sum — the total is fixed the instant the hold is taken.
                $amount = $chronological->sum(static fn (CourtSlot $s): float => (float) $s->price);

                $booking = new Booking;
                $booking->forceFill([
                    'code' => $this->uniqueCode(),
                    'court_id' => $primary->court_id,
                    'court_slot_id' => $primary->getKey(),
                    'customer_name' => $details['customer_name'],
                    'customer_phone' => $details['customer_phone'],
                    'customer_email' => $details['customer_email'],
                    'notes' => $details['notes'],
                    'amount' => $amount,
                    'status' => self::AWAITING_PAYMENT,
                    'hold_expires_at' => now()->addMinutes($holdMinutes),
                    'ip_address' => Str::limit((string) Request::ip(), 45, ''),
                    'user_agent' => Str::limit((string) Request::userAgent(), 500, ''),
                ])->save();

                // Every locked slot is held for this booking, not just the
                // primary one.
                foreach ($chronological as $candidate) {
                    $candidate->forceFill([
                        'status' => self::SLOT_HELD,
                        'held_booking_id' => $booking->getKey(),
                    ])->save();
                }

                // Durable history, separate from the live held_booking_id
                // pointer above: reject()/cancel()/expire() null that pointer
                // out again once a slot goes back on sale, which would make
                // this booking's own schedule unreadable the moment it stops
                // holding its slots. These rows are written once, here, and
                // never modified or deleted afterward — see Booking::slots().
                $now = now();
                DB::table('booking_slots')->insert(
                    $chronological->map(static fn (CourtSlot $s): array => [
                        'booking_id' => $booking->getKey(),
                        'court_slot_id' => $s->getKey(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );

                $slotCount = $chronological->count();
                $timeRanges = $slotCount <= 4
                    ? $chronological->map(fn (CourtSlot $s): string => $this->slotTimeRange($s))->implode(', ')
                    : sprintf('%d time slots', $slotCount);

                // Name every DISTINCT court the booking spans, in chronological
                // first-appearance order: a cross-court hold must read honestly
                // as "Court 1, Court 2" in the trail, while a single-court one
                // still reads as just its one court. Load the relation once so
                // naming every court is not an N+1.
                $chronological->loadMissing('court');
                $courtLabel = implode(', ', array_values(array_unique(
                    $chronological->map(fn (CourtSlot $s): string => $this->slotCourtName($s))->all(),
                )));

                $this->audit->log(
                    module: 'Bookings',
                    action: 'create',
                    description: sprintf(
                        'Reserved %s for %s — %s, %s (%d slot%s, %s). Hold expires %s.',
                        (string) $booking->code,
                        (string) $booking->customer_name,
                        $courtLabel,
                        $timeRanges,
                        $slotCount,
                        $slotCount === 1 ? '' : 's',
                        $this->formatMoney($amount),
                        $this->formatDateTime($booking->hold_expires_at),
                    ),
                    model: $booking,
                    newValues: [
                        'status' => self::AWAITING_PAYMENT,
                        'amount' => (string) $booking->amount,
                        'court_slot_id' => $primary->getKey(),
                        'slot_count' => $slotCount,
                        'hold_expires_at' => $this->formatDateTime($booking->hold_expires_at),
                    ],
                );

                return $booking;
            });
        });

        $booking->refresh();

        // Only after commit, same as every other customer notification in
        // this class — a dead mailer must never roll back a real hold.
        $this->notifyCustomer($booking, new BookingReserved($booking));
        $this->notifyOwner($booking, OwnerBookingAlert::EVENT_RESERVED);

        return $booking;
    }

    /* ===================================================================== */
    /* 1b. reserveManually() — the desk keys one in                           */
    /* ===================================================================== */

    /**
     * Record a booking the club took directly — over chat, over the phone, or
     * across the counter — instead of through the public checkout.
     *
     * This is deliberately NOT reserve() with a flag. It shares reserve()'s
     * locking discipline exactly (dedupe, sort ascending, lock the whole set
     * in one query, re-read status from the locked rows, all-or-nothing), but
     * it differs on four decisions that only make sense for a booking a
     * trusted human is standing behind:
     *
     *  - **No hold clock.** `hold_expires_at` stays null, so the release
     *    scheduler — which scans `whereNotNull('hold_expires_at')` — never
     *    sees it. A court held for a friend of the owner must not evaporate
     *    thirty minutes later because nobody clicked anything.
     *  - **No past-date guard.** reserve() refuses a slot that has already
     *    started, because a guest booking one is always a mistake. The desk
     *    keying one in is the opposite: it is how last night's walk-in gets
     *    into the books at all.
     *  - **It may land already settled.** There is no payment to verify, so
     *    `confirmed` (or `completed`, below) is reachable in one step.
     *  - **It stays silent.** See notifyCustomer() — nobody mails a customer
     *    about a booking they made by talking to a person.
     *
     * A blocked slot is still refused. Blocking is how the club takes a court
     * off the market for maintenance or a private event, and a manual booking
     * that could silently overwrite that would make the block meaningless;
     * staff who really want the hour unblock it first, deliberately.
     *
     * @param  array<int, int|string>  $slotIds  every court_slot id being claimed
     * @param  array<string, mixed>  $customer  name / phone / email / notes
     * @param  string  $mode  one of self::MANUAL_MODES
     *
     * @throws BookingException when any slot is gone or blocked
     * @throws InvalidArgumentException on an unknown mode
     */
    public function reserveManually(array $slotIds, array $customer, User $actor, string $mode): Booking
    {
        if (! in_array($mode, self::MANUAL_MODES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown manual booking mode "%s".', $mode));
        }

        $details = $this->normaliseCustomer($customer);

        // Same fixed ascending lock order as reserve(). The desk and the
        // public site claim slots out of the same table, so they must agree on
        // lock ordering or a staff member and a guest racing for the same hour
        // can deadlock each other.
        $ids = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $slotIds,
        )));
        sort($ids);

        if ($ids === []) {
            throw BookingException::slotUnavailable();
        }

        $booking = DB::transaction(function () use ($ids, $details, $actor, $mode): Booking {
            AuditTrailService::actingAs($actor);

            try {
                return AuditTrailService::withoutAuditing(function () use ($ids, $details, $actor, $mode): Booking {
                    $locked = CourtSlot::query()
                        ->whereIn('id', $ids)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    if ($locked->count() !== count($ids)) {
                        throw BookingException::slotUnavailable();
                    }

                    // All-or-nothing, exactly as reserve(): throwing here
                    // aborts the one transaction wrapping every write below,
                    // so a clash on any single hour leaves none of them held.
                    foreach ($locked as $candidate) {
                        $slotState = $this->statusOf($candidate, 'status');

                        if ($slotState === self::SLOT_BLOCKED) {
                            throw BookingException::slotBlocked();
                        }

                        if ($slotState !== self::SLOT_AVAILABLE) {
                            throw BookingException::slotUnavailable();
                        }
                    }

                    $chronological = $locked->sortBy([
                        ['slot_date', 'asc'],
                        ['start_time', 'asc'],
                    ])->values();
                    $primary = $chronological->first();

                    $amount = $chronological->sum(static fn (CourtSlot $s): float => (float) $s->price);

                    // A session that has already finished is history, not a
                    // reservation, so it is recorded as `completed` whatever
                    // mode was asked for — "reserve" and "confirm" are both
                    // statements about a court someone can still walk onto.
                    //
                    // The test is the LAST end, not the first start: a walk-in
                    // keyed in mid-session (the 9pm hour running now, plus the
                    // 10pm hour after it) is still very much in progress, and
                    // must land as a live booking rather than as history.
                    $endsAt = $chronological->reduce(
                        static fn (?CarbonInterface $latest, CourtSlot $s): CarbonInterface => $latest === null || $s->endsAt()->greaterThan($latest)
                            ? $s->endsAt()
                            : $latest,
                    );
                    $alreadyPlayed = $endsAt?->isPast() === true;

                    $status = match (true) {
                        $alreadyPlayed => self::COMPLETED,
                        $mode === self::MANUAL_MODE_CONFIRMED => self::CONFIRMED,
                        default => self::AWAITING_PAYMENT,
                    };

                    // Only an unpaid hold leaves the slot merely held; both
                    // settled outcomes sell the hour outright.
                    $slotStatus = $status === self::AWAITING_PAYMENT
                        ? self::SLOT_HELD
                        : self::SLOT_BOOKED;

                    $settled = $status !== self::AWAITING_PAYMENT;

                    $booking = new Booking;
                    $booking->forceFill([
                        'code' => $this->uniqueCode(),
                        'court_id' => $primary->court_id,
                        'court_slot_id' => $primary->getKey(),
                        'customer_name' => $details['customer_name'],
                        'customer_phone' => $details['customer_phone'],
                        'customer_email' => $details['customer_email'],
                        'notes' => $details['notes'],
                        'amount' => $amount,
                        'status' => $status,
                        'source' => Booking::SOURCE_MANUAL,
                        'created_by' => $actor->getKey(),
                        // Cash is the honest record of a booking settled with
                        // the club directly; an unpaid hold names no method at
                        // all, because none has been chosen yet.
                        'payment_method' => $settled ? Booking::PAYMENT_METHOD_CASH : null,
                        // Never expires — the whole point of a desk-made hold.
                        'hold_expires_at' => null,
                        'confirmed_at' => $settled ? now() : null,
                        'confirmed_by' => $settled ? $actor->getKey() : null,
                        // The staff member's own request, which is the truth:
                        // this booking was made from the admin area, and the
                        // trail should be able to say from where.
                        'ip_address' => Str::limit((string) Request::ip(), 45, ''),
                        'user_agent' => Str::limit((string) Request::userAgent(), 500, ''),
                    ])->save();

                    foreach ($chronological as $candidate) {
                        $candidate->forceFill([
                            'status' => $slotStatus,
                            'held_booking_id' => $booking->getKey(),
                        ])->save();
                    }

                    // Durable slot history, same contract as reserve().
                    $now = now();
                    DB::table('booking_slots')->insert(
                        $chronological->map(static fn (CourtSlot $s): array => [
                            'booking_id' => $booking->getKey(),
                            'court_slot_id' => $s->getKey(),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all()
                    );

                    $slotCount = $chronological->count();
                    $timeRanges = $slotCount <= 4
                        ? $chronological->map(fn (CourtSlot $s): string => $this->slotTimeRange($s))->implode(', ')
                        : sprintf('%d time slots', $slotCount);

                    $chronological->loadMissing('court');
                    $courtLabel = implode(', ', array_values(array_unique(
                        $chronological->map(fn (CourtSlot $s): string => $this->slotCourtName($s))->all(),
                    )));

                    $this->audit->log(
                        module: 'Bookings',
                        action: 'create',
                        description: sprintf(
                            'Manually booked %s for %s — %s, %s %s (%d slot%s, %s). Recorded as %s.',
                            (string) $booking->code,
                            (string) $booking->customer_name,
                            $courtLabel,
                            $this->slotDate($primary),
                            $timeRanges,
                            $slotCount,
                            $slotCount === 1 ? '' : 's',
                            $this->formatMoney($amount),
                            $this->statusLabel($status),
                        ),
                        model: $booking,
                        newValues: [
                            'status' => $status,
                            'source' => Booking::SOURCE_MANUAL,
                            'amount' => (string) $booking->amount,
                            'court_slot_id' => $primary->getKey(),
                            'slot_count' => $slotCount,
                            'slot_status' => $slotStatus,
                        ],
                    );

                    return $booking;
                });
            } finally {
                AuditTrailService::actingAs(null);
            }
        });

        // No notifications, by design — see notifyCustomer(). The staff member
        // who keyed this in is the one already talking to the customer.
        return $booking->refresh();
    }

    /* ===================================================================== */
    /* 2. submitPayment() — guest hands over the payment reference             */
    /* ===================================================================== */

    /**
     * Record proof of payment and hand the booking to the admin queue.
     *
     * The hold is *extended*, not cleared: the slot must stay off the market
     * long enough for a human to open the customer's payment app and check.
     *
     * @param  ?string  $reference  the reference number, if the caller has one.
     *                              Nullable because the checkout no longer asks
     *                              for it — the uploaded screenshot ($proofPath)
     *                              is the proof now.
     * @param  ?string  $method  which app the reference came from, one of
     *                           Booking::PAYMENT_METHODS. Nullable because not
     *                           every caller can name one (a booking taken over
     *                           the phone, a replayed submission) — and because
     *                           null must leave an already-stored method alone
     *                           rather than blanking it.
     *
     * @throws BookingException
     */
    public function submitPayment(
        Booking $booking,
        ?string $reference = null,
        ?string $proofPath = null,
        ?string $method = null,
    ): Booking {
        // Collapse '' to null the way $method does below: the array_filter
        // further down only drops nulls, so an empty string would happily
        // clobber a reference already on the row.
        $reference = $reference !== null ? trim($reference) : null;
        $reference = $reference === '' ? null : $reference;
        $method = $method !== null ? trim($method) : null;
        $method = $method === '' ? null : $method;
        $status = $this->statusOf($booking, 'status');

        // Idempotent: a double-submitted form must not re-notify the admin.
        if ($status === self::PENDING_VERIFICATION) {
            return $booking;
        }

        if ($status !== self::AWAITING_PAYMENT) {
            throw BookingException::notAwaitingPayment($booking);
        }

        if ($this->holdHasLapsed($booking)) {
            throw BookingException::holdExpired();
        }

        $verificationMinutes = (int) ($this->settings->get(Setting::GROUP_SYSTEM, 'booking_verification_hold_minutes') ?: config('booking.verification_hold_minutes', 720));

        DB::transaction(function () use ($booking, $reference, $proofPath, $method, $verificationMinutes): void {
            AuditTrailService::withoutAuditing(function () use ($booking, $reference, $proofPath, $method, $verificationMinutes): void {
                // Re-read under a lock: the release scheduler may have expired
                // this exact hold in the moment between the checks above and
                // this transaction starting (slow proof upload, queued I/O,
                // network latency are routine on a public form). Without this
                // re-check we would resurrect an already-expired-and-reassigned
                // booking, exactly the double-allocation this class exists to
                // prevent.
                $fresh = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->first();

                if (! $fresh instanceof Booking
                    || $this->statusOf($fresh, 'status') !== self::AWAITING_PAYMENT) {
                    throw BookingException::notAwaitingPayment($booking);
                }

                if ($this->holdHasLapsed($fresh)) {
                    throw BookingException::holdExpired();
                }

                $fresh->forceFill(array_filter([
                    'status' => self::PENDING_VERIFICATION,
                    'payment_reference' => $reference,
                    'payment_method' => $method,
                    'payment_proof_path' => $proofPath,
                    'payment_submitted_at' => now(),
                    'hold_expires_at' => now()->addMinutes($verificationMinutes),
                ], static fn (mixed $v): bool => $v !== null))->save();

                // Read the label off the saved row, not off $method: for a
                // booking that predates the choice this correctly stays
                // unnamed instead of the trail asserting "GCash" on a
                // reference nobody ever tied to GCash.
                $methodLabel = $fresh->paymentMethodLabel();

                $this->audit->log(
                    module: 'Bookings',
                    action: 'update',
                    description: sprintf(
                        // The whole point of naming the method here: the
                        // verifier reading this line needs to know which app
                        // to open before the reference (if any) means anything.
                        'Payment details submitted for %s via %s (ref: %s, amount: %s). Awaiting verification until %s.',
                        (string) $fresh->code,
                        $methodLabel ?? 'an unspecified app',
                        $reference ?? 'none',
                        $this->formatMoney($fresh->amount),
                        $this->formatDateTime($fresh->hold_expires_at),
                    ),
                    model: $fresh,
                    oldValues: ['status' => self::AWAITING_PAYMENT],
                    newValues: [
                        'status' => self::PENDING_VERIFICATION,
                        'payment_reference' => $reference,
                        'payment_method' => $fresh->payment_method,
                        'hold_expires_at' => $this->formatDateTime($fresh->hold_expires_at),
                    ],
                );
            });
        });

        $booking->refresh();

        $this->notifyVerifiers($booking);

        return $booking;
    }

    /* ===================================================================== */
    /* 3. confirm() — admin verified the payment                              */
    /* ===================================================================== */

    /**
     * Verify the payment: the slot becomes permanently booked.
     *
     * @throws BookingException
     */
    public function confirm(Booking $booking, User $actor): Booking
    {
        $status = $this->statusOf($booking, 'status');

        // Idempotent guard — never re-stamp confirmed_at or re-mail a customer.
        if (in_array($status, [self::CONFIRMED, self::COMPLETED], true)) {
            return $booking;
        }

        if (! in_array($status, $this->confirmableFrom($booking), true)) {
            throw BookingException::invalidTransition($booking, $status, self::CONFIRMED);
        }

        DB::transaction(function () use ($booking, $actor): void {
            AuditTrailService::actingAs($actor);

            try {
                AuditTrailService::withoutAuditing(function () use ($booking, $actor): void {
                    // Lock the booking row first, then the slot. Every other
                    // transition (expire(), and reject()/cancel() below) locks
                    // the booking before the slot; confirm() must acquire the
                    // same two locks in the same order or two transitions
                    // racing on the same rows can deadlock each other.
                    //
                    // Re-read the booking status inside the lock: two admins
                    // can click Confirm at the same moment, or the release
                    // scheduler can be mid-expiry on this exact row.
                    $fresh = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->first();

                    // Re-derive the allowed set from the LOCKED row, not from
                    // the caller's copy: `source` decides what may confirm, so
                    // the decision has to be made about the row we own.
                    if (! $fresh instanceof Booking
                        || ! in_array($this->statusOf($fresh, 'status'), $this->confirmableFrom($fresh), true)) {
                        throw BookingException::invalidTransition(
                            $booking,
                            $fresh instanceof Booking ? $this->statusOf($fresh, 'status') : self::EXPIRED,
                            self::CONFIRMED,
                        );
                    }

                    $from = $this->statusOf($fresh, 'status');

                    $slots = $this->lockSlotsFor($fresh);

                    // Every slot the booking holds becomes permanently
                    // booked, not just the primary one.
                    foreach ($slots as $slot) {
                        $slot->forceFill([
                            'status' => self::SLOT_BOOKED,
                            'held_booking_id' => $booking->getKey(),
                        ])->save();
                    }

                    $booking->forceFill([
                        'status' => self::CONFIRMED,
                        'confirmed_at' => now(),
                        'confirmed_by' => $actor->getKey(),
                        // The hold is over: this slot is sold.
                        'hold_expires_at' => null,
                        'rejected_at' => null,
                        'rejected_by' => null,
                        'rejection_reason' => null,
                    ])->save();

                    // The primary (earliest) slot, for the singular audit
                    // wording — the same convention reserve() established.
                    $displaySlot = $slots->sortBy([
                        ['slot_date', 'asc'],
                        ['start_time', 'asc'],
                    ])->first();

                    $this->audit->log(
                        module: 'Bookings',
                        action: 'update',
                        description: sprintf(
                            'Confirmed booking %s for %s — %s, %s %s (%s, ref: %s).',
                            (string) $booking->code,
                            (string) $booking->customer_name,
                            $this->courtName($booking),
                            $this->slotDate($displaySlot),
                            $this->slotTimeRange($displaySlot),
                            $this->formatMoney($booking->amount),
                            (string) ($booking->payment_reference ?: 'none'),
                        ),
                        model: $booking,
                        // The status actually left behind, which is no longer
                        // always pending_verification: a desk-made hold is
                        // confirmed straight out of awaiting_payment.
                        oldValues: ['status' => $from],
                        newValues: ['status' => self::CONFIRMED, 'slot_status' => self::SLOT_BOOKED],
                    );
                });
            } finally {
                AuditTrailService::actingAs(null);
            }
        });

        $booking->refresh();

        $this->notifyCustomer($booking, new BookingConfirmed($booking));
        $this->notifyOwner($booking, OwnerBookingAlert::EVENT_CONFIRMED);

        return $booking;
    }

    /**
     * Which statuses confirm() will accept as a starting point.
     *
     * For a public booking the answer is the one it has always been: a guest
     * must have submitted proof, so only `pending_verification` may be
     * confirmed. Confirming an `awaiting_payment` guest booking would be
     * signing off on a payment nobody ever saw.
     *
     * A desk-made hold has no such step and never will — there is no proof to
     * submit, because the money was arranged with a person. Blocking it out of
     * `awaiting_payment` would leave it permanently unconfirmable: staff could
     * hold a court for the owner's friend and then have no way to close the
     * booking when the friend actually paid.
     *
     * @return list<string>
     */
    private function confirmableFrom(Booking $booking): array
    {
        return $booking->isManual()
            ? [self::PENDING_VERIFICATION, self::AWAITING_PAYMENT]
            : [self::PENDING_VERIFICATION];
    }

    /* ===================================================================== */
    /* 4. reject() — admin could not match the payment                        */
    /* ===================================================================== */

    /**
     * Reject the payment and put the slot back on sale.
     *
     * @throws BookingException
     */
    public function reject(Booking $booking, User $actor, ?string $reason = null): Booking
    {
        $status = $this->statusOf($booking, 'status');

        if ($status === self::REJECTED) {
            return $booking;
        }

        if (! in_array($status, self::HOLD_STATUSES, true)) {
            throw BookingException::invalidTransition($booking, $status, self::REJECTED);
        }

        $reason = $reason !== null ? trim($reason) : null;
        $reason = $reason === '' ? null : $reason;

        DB::transaction(function () use ($booking, $actor, $reason, $status): void {
            AuditTrailService::actingAs($actor);

            try {
                AuditTrailService::withoutAuditing(function () use ($booking, $actor, $reason, $status): void {
                    // Lock and re-read the booking row itself before writing.
                    // releaseSlotsFor() only locks/rechecks the slot rows — it
                    // can't protect the booking row from a concurrent confirm()
                    // that has already moved this exact booking on (two admins
                    // acting on the same pending_verification booking at once).
                    $fresh = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->first();

                    if (! $fresh instanceof Booking
                        || ! in_array($this->statusOf($fresh, 'status'), self::HOLD_STATUSES, true)) {
                        throw BookingException::invalidTransition(
                            $booking,
                            $fresh instanceof Booking ? $this->statusOf($fresh, 'status') : self::EXPIRED,
                            self::REJECTED,
                        );
                    }

                    $this->releaseSlotsFor($fresh);

                    $fresh->forceFill([
                        'status' => self::REJECTED,
                        'rejected_at' => now(),
                        'rejected_by' => $actor->getKey(),
                        'rejection_reason' => $reason,
                        'hold_expires_at' => null,
                    ])->save();

                    $this->audit->log(
                        module: 'Bookings',
                        action: 'update',
                        description: sprintf(
                            'Rejected booking %s for %s%s. Slot returned to available.',
                            (string) $fresh->code,
                            (string) $fresh->customer_name,
                            $reason !== null ? sprintf(' (reason: %s)', Str::limit($reason, 120)) : '',
                        ),
                        model: $fresh,
                        oldValues: ['status' => $status],
                        newValues: [
                            'status' => self::REJECTED,
                            'rejection_reason' => $reason,
                            'slot_status' => self::SLOT_AVAILABLE,
                        ],
                    );
                });
            } finally {
                AuditTrailService::actingAs(null);
            }
        });

        $booking->refresh();

        $this->notifyCustomer($booking, new BookingRejected($booking));

        return $booking;
    }

    /* ===================================================================== */
    /* 5. cancel() — the customer changed their mind                          */
    /* ===================================================================== */

    /**
     * Customer-initiated cancellation, only while the slot is merely on hold.
     * Once a booking is confirmed, cancelling is a staff conversation, not a
     * self-service button.
     *
     * @throws BookingException
     */
    public function cancel(Booking $booking): Booking
    {
        $status = $this->statusOf($booking, 'status');

        if ($status === self::CANCELLED) {
            return $booking;
        }

        if (! in_array($status, self::HOLD_STATUSES, true)) {
            throw BookingException::notCancellable($booking);
        }

        DB::transaction(function () use ($booking, $status): void {
            AuditTrailService::withoutAuditing(function () use ($booking, $status): void {
                // Lock and re-read the booking row itself before writing —
                // see the identical guard in reject(): releaseSlotsFor() alone
                // only protects the slot rows, not this row, from a
                // concurrent confirm() that already moved the booking on.
                $fresh = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->first();

                if (! $fresh instanceof Booking
                    || ! in_array($this->statusOf($fresh, 'status'), self::HOLD_STATUSES, true)) {
                    throw BookingException::notCancellable($booking);
                }

                $this->releaseSlotsFor($fresh);

                $fresh->forceFill([
                    'status' => self::CANCELLED,
                    'cancelled_at' => now(),
                    'hold_expires_at' => null,
                ])->save();

                $this->audit->log(
                    module: 'Bookings',
                    action: 'update',
                    description: sprintf(
                        'Booking %s cancelled by the customer (%s). Slot returned to available.',
                        (string) $fresh->code,
                        (string) $fresh->customer_name,
                    ),
                    model: $fresh,
                    oldValues: ['status' => $status],
                    newValues: ['status' => self::CANCELLED, 'slot_status' => self::SLOT_AVAILABLE],
                );
            });
        });

        return $booking->refresh();
    }

    /* ===================================================================== */
    /* 6. complete() — post-play bookkeeping                                  */
    /* ===================================================================== */

    /**
     * Mark a played booking as completed. The slot stays `booked` — the game
     * happened, the slot was consumed, and reporting needs that history.
     *
     * @throws BookingException
     */
    public function complete(Booking $booking, User $actor): Booking
    {
        $status = $this->statusOf($booking, 'status');

        if ($status === self::COMPLETED) {
            return $booking;
        }

        if ($status !== self::CONFIRMED) {
            throw BookingException::invalidTransition($booking, $status, self::COMPLETED);
        }

        DB::transaction(function () use ($booking, $actor): void {
            AuditTrailService::actingAs($actor);

            try {
                AuditTrailService::withoutAuditing(function () use ($booking): void {
                    $booking->forceFill(['status' => self::COMPLETED])->save();

                    $this->audit->log(
                        module: 'Bookings',
                        action: 'update',
                        description: sprintf(
                            'Marked booking %s as completed (%s, %s).',
                            (string) $booking->code,
                            (string) $booking->customer_name,
                            $this->formatMoney($booking->amount),
                        ),
                        model: $booking,
                        oldValues: ['status' => self::CONFIRMED],
                        newValues: ['status' => self::COMPLETED],
                    );
                });
            } finally {
                AuditTrailService::actingAs(null);
            }
        });

        return $booking->refresh();
    }

    /* ===================================================================== */
    /* 7. expire() — the scheduled release                                    */
    /* ===================================================================== */

    /**
     * Release a lapsed hold. Called only by `bookings:release-holds`.
     *
     * Returns false rather than throwing when the booking has already moved on
     * — the release job races with real customers by design, and losing that
     * race is a normal outcome, not an error.
     */
    public function expire(Booking $booking): bool
    {
        $status = $this->statusOf($booking, 'status');

        if (! in_array($status, self::HOLD_STATUSES, true)) {
            return false;
        }

        return (bool) DB::transaction(function () use ($booking, $status): bool {
            return AuditTrailService::asSystem(function () use ($booking, $status): bool {
                return AuditTrailService::withoutAuditing(function () use ($booking, $status): bool {
                    // Re-read under a lock: the customer may have submitted
                    // their payment in the moment between the scan and here.
                    $fresh = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->first();

                    if (! $fresh instanceof Booking) {
                        return false;
                    }

                    if (! in_array($this->statusOf($fresh, 'status'), self::HOLD_STATUSES, true)) {
                        return false;
                    }

                    if (! $this->holdHasLapsed($fresh)) {
                        return false;
                    }

                    $this->releaseSlotsFor($fresh);

                    $fresh->forceFill([
                        'status' => self::EXPIRED,
                        'hold_expires_at' => null,
                    ])->save();

                    $this->audit->log(
                        module: 'Bookings',
                        action: 'update',
                        description: sprintf(
                            'Hold expired for booking %s (%s). Slot returned to available.',
                            (string) $fresh->code,
                            (string) $fresh->customer_name,
                        ),
                        model: $fresh,
                        oldValues: ['status' => $status],
                        newValues: ['status' => self::EXPIRED, 'slot_status' => self::SLOT_AVAILABLE],
                    );

                    return true;
                });
            }, 'Scheduler');
        });
    }

    /* ===================================================================== */
    /* Slot release — the ONE implementation                                  */
    /* ===================================================================== */

    /**
     * Return every one of a booking's slots to the market.
     *
     * Deliberately the only code path that writes `available` back onto a
     * slot, so reject / cancel / expire can never drift apart. Must be called
     * inside a transaction.
     *
     * The same two safety conditions apply to EVERY row, not just one:
     *
     *  - only a slot that is currently `held` is touched, so a slot that has
     *    since been `booked` or `blocked` is never quietly reopened;
     *  - only when the hold belongs to *this* booking, so a stale expiry job
     *    cannot steal a slot a newer customer has just claimed.
     */
    private function releaseSlotsFor(Booking $booking): void
    {
        foreach ($this->lockSlotsFor($booking) as $slot) {
            if ($this->statusOf($slot, 'status') !== self::SLOT_HELD) {
                continue;
            }

            $holder = $slot->held_booking_id;

            if ($holder !== null && (int) $holder !== (int) $booking->getKey()) {
                continue;
            }

            $slot->forceFill([
                'status' => self::SLOT_AVAILABLE,
                'held_booking_id' => null,
            ])->save();
        }
    }

    /**
     * Hand back every slot a booking still occupies, whatever its status —
     * the deletion path's counterpart to releaseSlotsFor() above.
     *
     * releaseSlotsFor() is deliberately narrow: it frees `held` rows only,
     * because reject() / cancel() / expire() walk a booking back off a hold
     * and must never reopen an hour that has since been sold to someone else
     * or taken off the market. Deletion is the one transition where that
     * narrowness is wrong. confirm() promotes a booking's slots to `booked`
     * and complete() deliberately leaves them there, so a confirmed or
     * completed booking holds hours no status-gated release will ever touch.
     * Delete that booking and the hours sit `booked` forever, pointing at a
     * row that no longer exists — gone from the admin list, still unsellable
     * on the public grid, and nothing left on screen to explain why.
     *
     * `blocked` is the one status left as it stands: staff took that hour off
     * the market on purpose, and deleting a booking is no reason to put it
     * back on sale. Its pointer is still cleared, because after the delete it
     * refers to nothing either way.
     *
     * Opens its own transaction — call it before the delete, not inside one.
     * Slot writes are suppressed from the audit trail and summarised in a
     * single entry, so freeing a six-slot booking does not bury the deletion
     * it belongs to under six near-identical "Slots" rows.
     *
     * @return int how many hours went back on sale
     */
    public function releaseSlotsOnDeletion(Booking $booking): int
    {
        return DB::transaction(function () use ($booking): int {
            return AuditTrailService::withoutAuditing(function () use ($booking): int {
                $freed = 0;

                foreach ($this->lockSlotsFor($booking) as $slot) {
                    $status = $this->statusOf($slot, 'status');
                    $sellable = in_array($status, [self::SLOT_HELD, self::SLOT_BOOKED], true);

                    $slot->forceFill([
                        'status' => $sellable ? self::SLOT_AVAILABLE : $status,
                        'held_booking_id' => null,
                    ])->save();

                    if ($sellable) {
                        $freed++;
                    }
                }

                if ($freed > 0) {
                    $this->audit->log(
                        module: 'Bookings',
                        action: 'update',
                        description: sprintf(
                            '%d %s freed from deleted booking %s (%s) and returned to available.',
                            $freed,
                            Str::plural('slot', $freed),
                            (string) $booking->code,
                            (string) $booking->customer_name,
                        ),
                        model: $booking,
                        oldValues: ['slot_status' => self::SLOT_BOOKED],
                        newValues: ['slot_status' => self::SLOT_AVAILABLE, 'slots_freed' => $freed],
                    );
                }

                return $freed;
            });
        });
    }

    /**
     * Fetch every slot currently held by a booking, with a row lock, ordered
     * ascending by id — the same deterministic lock-order reasoning as
     * reserve(): every transition that touches a set of slot rows locks them
     * in one fixed order, so two transitions racing on overlapping slot sets
     * can never deadlock each other. Must be called in a transaction.
     *
     * @return Collection<int, CourtSlot>
     */
    private function lockSlotsFor(Booking $booking): Collection
    {
        return CourtSlot::query()
            ->where('held_booking_id', $booking->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /* ===================================================================== */
    /* Notifications — always after commit, never fatal                       */
    /* ===================================================================== */

    /**
     * Mail (when we have an address) and SMS (when the gateway is on) to the
     * customer. Customers are not users, so this goes out on-demand.
     */
    private function notifyCustomer(Booking $booking, object $notification): void
    {
        // A desk-made booking was arranged in a conversation, and the customer
        // already knows they have the court — the staff member who keyed it in
        // is the same person who told them. Mailing an automated "we received
        // your booking" on top of that reads as a system glitch, not as a
        // confirmation, and for a court booked for a friend of the owner it can
        // arrive as the first the customer hears of an email address the club
        // wrote down for them.
        //
        // Gated here rather than at each call site so it holds for every
        // transition a manual booking can go through — created, later
        // confirmed, cancelled — instead of only the one it was created by.
        if ($booking->isManual()) {
            return;
        }

        try {
            $routes = [];

            $email = trim((string) $booking->customer_email);
            $phone = trim((string) $booking->customer_phone);

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $routes['mail'] = $email;
            }

            if ($phone !== '') {
                $routes['sms'] = $phone;
            }

            if ($routes === []) {
                return;
            }

            $notifiable = Notification::route(
                (string) array_key_first($routes),
                $routes[array_key_first($routes)],
            );

            foreach (array_slice($routes, 1, null, true) as $channel => $route) {
                $notifiable->route($channel, $route);
            }

            $notifiable->notify($notification);
        } catch (Throwable $e) {
            $this->logNotificationFailure($booking, $notification, $e);
        }
    }

    /**
     * Tell everyone who can verify payments that work has arrived.
     *
     * Recipients are derived from the permission, never a role name, so a new
     * "Cashier" role starts receiving these the moment it is granted
     * `bookings.verify` — no code change.
     */
    private function notifyVerifiers(Booking $booking): void
    {
        $notification = new NewBookingSubmitted($booking);

        try {
            $recipients = User::query()
                ->permission('bookings.verify')
                ->where('is_active', true)
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->get(['id', 'name', 'email', 'phone']);

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, $notification);

                return;
            }
        } catch (Throwable $e) {
            // Permission tables missing, or spatie not booted in a console
            // context — fall through to the configured address.
            Log::warning('Could not resolve booking verifiers.', ['error' => $e->getMessage()]);
        }

        $fallback = (string) (config('booking.admin_email') ?: config('mail.from.address', ''));

        if ($fallback === '' || ! filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            Notification::route('mail', $fallback)->notify($notification);
        } catch (Throwable $e) {
            $this->logNotificationFailure($booking, $notification, $e);
        }
    }

    /**
     * A plain "someone booked / that booking is confirmed" reminder to the
     * owner's personal inbox, when they have opted in by setting an address in
     * Admin > Settings > Booking. Mail-only and best-effort: it is a courtesy
     * notice, so a bad address or a dead mailer must never disturb the booking
     * flow it is merely reporting on.
     *
     * @param  string  $event  one of OwnerBookingAlert::EVENT_*
     */
    private function notifyOwner(Booking $booking, string $event): void
    {
        // Nothing to report on a desk-made booking: this alert exists to tell
        // the owner that business arrived while they weren't looking, and a
        // manual booking is one their own staff just typed in — usually after
        // the owner personally agreed to it.
        if ($booking->isManual()) {
            return;
        }

        $email = $this->ownerNotificationEmail();

        if ($email === null) {
            return;
        }

        $notification = new OwnerBookingAlert($booking, $event);

        try {
            Notification::route('mail', $email)->notify($notification);
        } catch (Throwable $e) {
            $this->logNotificationFailure($booking, $notification, $e);
        }
    }

    /**
     * The owner's opted-in notification address, or null when unset/invalid.
     * Unlike the verifier fallback there is deliberately no config default —
     * blank means "don't send", not "fall back to the operations mailbox".
     */
    private function ownerNotificationEmail(): ?string
    {
        $email = trim((string) ($this->settings->get(Setting::GROUP_SYSTEM, 'owner_notification_email') ?: ''));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function logNotificationFailure(Booking $booking, object $notification, Throwable $e): void
    {
        Log::error('Booking notification could not be dispatched.', [
            'booking' => $booking->code ?? $booking->getKey(),
            'notification' => $notification::class,
            'error' => $e->getMessage(),
        ]);
    }

    /* ===================================================================== */
    /* Read model                                                             */
    /* ===================================================================== */

    /**
     * One flattened, presentation-ready view of a booking, shared by the mail
     * templates, the SMS copy and anything else that needs to describe it.
     *
     * Built defensively: a booking whose slot row was hard-deleted still
     * renders an email rather than throwing inside a queue worker.
     *
     * @return array<string, mixed>
     */
    public function summary(Booking $booking): array
    {
        $slot = $this->resolveSlot($booking);
        $slots = $this->resolveSlots($booking);
        // Load each slot's court once, up front, so every per-slot court_name
        // below resolves without an N+1 and a cross-court booking can name all
        // of its courts. Guarded: resolveSlots()'s defensive single-slot
        // fallback is a base collection with no loadMissing() — slotCourtName()
        // recovers that lone slot's court on its own.
        if ($slots instanceof Collection) {
            $slots->loadMissing('court');
        }
        $status = $this->statusOf($booking, 'status');
        $name = trim((string) $booking->customer_name);

        // The plural, chronological counterpart of the primary-slot fields
        // just above — one entry per slot, reusing the same formatting
        // helpers so the two can never describe the same slot differently.
        // Each entry also names its own court, so a cross-court booking can be
        // rendered slot-by-slot ("Court 1, 9–10 AM"); for a single-court
        // booking every entry simply repeats the one court.
        $slotSummaries = $slots->map(fn (CourtSlot $s): array => [
            'court_id' => (int) $s->court_id,
            'court_name' => $this->slotCourtName($s),
            'date' => $this->slotDate($s, 'D, j M Y'),
            'date_short' => $this->slotDate($s, 'j M'),
            'start' => $this->formatTime($s->start_time),
            'end' => $this->formatTime($s->end_time),
            'time_range' => $this->slotTimeRange($s),
        ])->values()->all();

        // The DISTINCT courts the booking spans, derived straight from the
        // per-slot entries so the two can never disagree: deduped by name and
        // kept in chronological first-appearance order. A single-court booking
        // yields exactly one name, so court_names stays in lock-step with the
        // primary court_name and every single-court surface renders as before.
        $courtNames = array_values(array_unique(array_column($slotSummaries, 'court_name')));
        $isMultiCourt = count(array_unique(array_column($slotSummaries, 'court_id'))) > 1;

        return [
            'id' => $booking->getKey(),
            'code' => (string) $booking->code,
            'status' => $status,
            'status_label' => $this->statusLabel($status),

            'court_name' => $this->courtName($booking, $slot),
            'date' => $this->slotDate($slot, 'D, j M Y'),
            'date_short' => $this->slotDate($slot, 'j M'),
            'start' => $this->formatTime($slot?->start_time),
            'end' => $this->formatTime($slot?->end_time),
            'time_range' => $this->slotTimeRange($slot),

            // Full multi-slot view: every slot this booking spans, in
            // chronological order, plus a single joined string so any
            // template that only interpolates one string (an SMS body, say)
            // can upgrade to describe every slot with a one-line change.
            'slot_count' => $slots->count(),
            'slots' => $slotSummaries,
            'time_range_full' => $slots->map(fn (CourtSlot $s): string => $this->slotTimeRange($s))->implode(', '),

            // Distinct courts spanned: court_names is the plural, chronological
            // counterpart of the primary court_name; is_multi_court lets a
            // template switch to the per-court layout only when it must, so a
            // single-court booking keeps its original single "Court" row.
            'court_names' => $courtNames,
            'is_multi_court' => $isMultiCourt,

            'amount' => $this->formatMoney($booking->amount),
            'amount_plain' => 'PHP '.number_format((float) $booking->amount, 2),
            'amount_raw' => round((float) $booking->amount, 2),

            'customer_name' => $name !== '' ? $name : 'Guest',
            'customer_first_name' => $name !== '' ? Str::before($name, ' ') : 'there',
            'customer_phone' => (string) $booking->customer_phone,
            'customer_email' => (string) $booking->customer_email,
            'notes' => (string) $booking->notes,

            'payment_reference' => (string) $booking->payment_reference,
            // Nullable, unlike the string-cast fields around it: a booking
            // taken before the site offered a choice has no method, and a mail
            // template that guessed would point the customer at the wrong app.
            'payment_method' => $booking->payment_method !== null
                ? (string) $booking->payment_method
                : null,
            'payment_method_label' => $booking->paymentMethodLabel(),
            'rejection_reason' => (string) $booking->rejection_reason,
            'hold_expires_at' => $booking->hold_expires_at !== null
                ? $this->formatDateTime($booking->hold_expires_at)
                : null,
        ];
    }

    /* ===================================================================== */
    /* Internals                                                              */
    /* ===================================================================== */

    /**
     * Normalise whatever key style the caller used into column names.
     *
     * @param  array<string, mixed>  $customer
     * @return array{customer_name: string, customer_phone: string, customer_email: ?string, notes: ?string}
     */
    private function normaliseCustomer(array $customer): array
    {
        $pick = static function (array $source, string ...$keys): ?string {
            foreach ($keys as $key) {
                if (isset($source[$key]) && trim((string) $source[$key]) !== '') {
                    return trim((string) $source[$key]);
                }
            }

            return null;
        };

        $email = $pick($customer, 'customer_email', 'email');

        return [
            'customer_name' => Str::limit((string) $pick($customer, 'customer_name', 'name'), 150, ''),
            'customer_phone' => Str::limit((string) $pick($customer, 'customer_phone', 'phone', 'mobile'), 30, ''),
            'customer_email' => $email !== null ? Str::lower(Str::limit($email, 190, '')) : null,
            'notes' => $pick($customer, 'notes', 'note', 'remarks'),
        ];
    }

    /**
     * Generate a booking code, retrying on the (astronomically unlikely)
     * collision rather than letting a unique-constraint violation surface as a
     * 500 on the customer's checkout.
     */
    private function uniqueCode(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = (string) Booking::generateCode();

            if ($code !== '' && ! Booking::withTrashed()->where('code', $code)->exists()) {
                return $code;
            }
        }

        // Last resort: guaranteed unique by construction.
        return sprintf(
            '%s-%s',
            (string) config('booking.code_prefix', 'AH'),
            Str::upper(Str::random(4)).Str::upper(base_convert((string) now()->getTimestampMs(), 10, 36)),
        );
    }

    /**
     * Read a status column as a plain string, whether the model casts it to a
     * PHP enum or leaves it as the raw database value.
     */
    private function statusOf(Model $model, string $column): string
    {
        $value = $model->getAttribute($column);

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return trim((string) $value);
    }

    private function holdHasLapsed(Booking $booking): bool
    {
        $expires = $booking->hold_expires_at;

        if ($expires === null) {
            return false;
        }

        return $this->toCarbon($expires)?->isPast() === true;
    }

    private function resolveSlot(Booking $booking): ?CourtSlot
    {
        if ($booking->court_slot_id === null) {
            return null;
        }

        // Use whatever relation the model exposes when it is already loaded,
        // so a well-eager-loaded list view costs no extra queries.
        foreach (['slot', 'courtSlot'] as $relation) {
            if ($booking->relationLoaded($relation)) {
                $loaded = $booking->getRelation($relation);

                if ($loaded instanceof CourtSlot) {
                    return $loaded;
                }
            }
        }

        return CourtSlot::query()->whereKey($booking->court_slot_id)->first();
    }

    /**
     * The plural counterpart of resolveSlot(): every slot this booking
     * holds, in chronological order. Same defensive relationLoaded-then-
     * fallback pattern — a well-eager-loaded list view costs no extra
     * queries, and a booking whose slots were hard-deleted still returns an
     * empty collection rather than throwing.
     *
     * @return Collection<int, CourtSlot>
     */
    private function resolveSlots(Booking $booking): Collection
    {
        $slots = $booking->relationLoaded('slots') && $booking->getRelation('slots') instanceof Collection
            ? $booking->getRelation('slots')
            : $booking->slots()->get();

        if ($slots->isNotEmpty()) {
            return $slots;
        }

        // Empty means one of two things: a booking made before the
        // booking_slots pivot existed (nothing to backfill it with), or a
        // genuinely slot-less booking. Either way, resolveSlot() can still
        // recover the one slot court_slot_id points at, so a booking never
        // silently reports zero slots just because it predates this table.
        $primary = $this->resolveSlot($booking);

        return new Collection($primary instanceof CourtSlot ? [$primary] : []);
    }

    private function courtName(Booking $booking, ?CourtSlot $slot = null): string
    {
        foreach (['court'] as $relation) {
            if ($booking->relationLoaded($relation)) {
                $court = $booking->getRelation($relation);

                if ($court instanceof Court) {
                    return (string) $court->name;
                }
            }
        }

        $courtId = $booking->court_id ?? $slot?->court_id;

        if ($courtId === null) {
            return 'Court';
        }

        $court = Court::query()->whereKey($courtId)->first(['id', 'name']);

        return $court instanceof Court ? (string) $court->name : 'Court';
    }

    /**
     * A single slot's court name — the per-slot counterpart of courtName().
     * Reads an already-loaded `court` relation when present (callers load it on
     * the whole slot collection first, so naming every court a cross-court
     * booking spans costs no extra queries) and falls back to a keyed lookup
     * for a lone, un-eager-loaded slot. Never nulls: an orphaned slot reads as
     * the same 'Court' placeholder courtName() uses.
     */
    private function slotCourtName(CourtSlot $slot): string
    {
        if ($slot->relationLoaded('court')) {
            $court = $slot->getRelation('court');

            if ($court instanceof Court) {
                return (string) $court->name;
            }
        }

        if ($slot->court_id === null) {
            return 'Court';
        }

        $court = Court::query()->whereKey($slot->court_id)->first(['id', 'name']);

        return $court instanceof Court ? (string) $court->name : 'Court';
    }

    private function slotDate(?CourtSlot $slot, string $format = 'D, j M Y'): string
    {
        return $this->toCarbon($slot?->slot_date)?->format($format) ?? '—';
    }

    private function slotTimeRange(?CourtSlot $slot): string
    {
        if ($slot === null) {
            return '—';
        }

        $start = $this->formatTime($slot->start_time);
        $end = $this->formatTime($slot->end_time);

        return $start === '—' && $end === '—' ? '—' : $start.' – '.$end;
    }

    /**
     * Combine the slot's date and start time into a single instant.
     */
    private function slotStartsAt(CourtSlot $slot): ?CarbonInterface
    {
        $date = $this->toCarbon($slot->slot_date);

        if ($date === null) {
            return null;
        }

        $time = $this->toCarbon($slot->start_time);

        return $time === null
            ? $date->copy()->startOfDay()
            : $date->copy()->setTime($time->hour, $time->minute, $time->second);
    }

    private function formatTime(mixed $value): string
    {
        return $this->toCarbon($value)?->format('g:i A') ?? '—';
    }

    private function formatDateTime(mixed $value): string
    {
        return $this->toCarbon($value)?->format('D, j M Y g:i A') ?? '—';
    }

    private function formatMoney(mixed $amount): string
    {
        return '₱'.number_format((float) $amount, 2);
    }

    /**
     * Parse anything the models might hand back — a Carbon instance, a `H:i:s`
     * time string, a `Y-m-d` date — without ever throwing.
     */
    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::AWAITING_PAYMENT => 'Awaiting payment',
            self::PENDING_VERIFICATION => 'Pending verification',
            self::CONFIRMED => 'Confirmed',
            self::COMPLETED => 'Completed',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled',
            self::EXPIRED => 'Expired',
            default => Str::headline($status),
        };
    }
}
