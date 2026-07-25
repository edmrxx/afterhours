<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicSite\LookupBookingRequest;
use App\Http\Requests\PublicSite\ReserveSlotRequest;
use App\Http\Requests\PublicSite\SubmitPaymentRequest;
use App\Models\Booking;
use App\Models\CourtSlot;
use App\Services\BookingService;
use App\Services\PaymentMethodService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The guest checkout: reserve → pay → status.
 *
 * Security model
 * --------------
 * There is no login here. The booking code is the only credential, which puts
 * two obligations on this controller:
 *
 *  1. **Nothing is trusted from the client.** The amount is never read from the
 *     request — it is the value BookingService copied off the slot at reserve
 *     time. The slot's availability is re-checked under a row lock inside the
 *     service, not here.
 *  2. **Nothing extra is exposed.** The payloads below are hand-built rather
 *     than dumping the model, so `ip_address`, `user_agent`, internal ids and
 *     the staff member who confirmed a booking never reach the browser.
 *
 * Route model binding resolves `{booking:code}` and honours the soft delete,
 * so a removed booking 404s rather than leaking.
 */
class PublicBookingController extends Controller
{
    /** Where payment screenshots live on the public disk. */
    private const PROOF_DIRECTORY = 'payment-proofs';

    public function __construct(
        private readonly BookingService $bookings,
        private readonly SettingsService $settings,
        private readonly PaymentMethodService $paymentMethods,
    ) {}

    /* ===================================================================== */
    /* POST /booking/reserve — claim the slot                                 */
    /* ===================================================================== */

    public function reserve(ReserveSlotRequest $request): RedirectResponse
    {
        // Already loaded (with its court) by the request's own checks.
        $slots = $request->slots();

        if ($slots->isEmpty()) {
            return redirect()
                ->route('public.courts.index')
                ->with('error', 'That time slot is no longer on the schedule. Please pick another one.');
        }

        $courtSlug = (string) ($slots->first()->court?->slug ?? '');

        try {
            // The service wants ids, not model instances.
            $booking = $this->bookings->reserve($slots->modelKeys(), $request->customer());
        } catch (BookingException $e) {
            // Losing the race is a normal outcome, not an error page: send the
            // customer straight back to the schedule with the reason.
            return $this->backToSchedule($courtSlug, $e->getMessage());
        }

        return redirect()
            ->route('public.booking.payment', ['booking' => $booking->code])
            ->with('success', 'We are holding your slot. Please complete payment to confirm it.');
    }

    /* ===================================================================== */
    /* GET /booking/{code}/payment — how to pay                               */
    /* ===================================================================== */

    public function payment(Booking $booking): Response|RedirectResponse
    {
        // `slots.court` alongside `slots`: a booking may span several courts,
        // so each slot needs its own court name — eager-loaded here so the
        // per-slot court in bookingPayload() never fires a query per slot.
        $booking->loadMissing(['court:id,name,slug,photo_path', 'slot', 'slots', 'slots.court:id,name']);

        if ($booking->status !== Booking::STATUS_AWAITING_PAYMENT) {
            return $this->redirectToStatus($booking);
        }

        if ($booking->isHoldExpired()) {
            return redirect()
                ->route('public.booking.status', ['booking' => $booking->code])
                ->with('warning', 'Your reservation hold has run out and the slot has been released.');
        }

        return Inertia::render('PublicSite/Payment', [
            'booking' => $this->bookingPayload($booking),
            'payment' => $this->paymentSettings(),
            'serverTime' => Carbon::now()->toIso8601String(),
        ]);
    }

    /* ===================================================================== */
    /* POST /booking/{code}/payment — the method and its reference            */
    /* ===================================================================== */

    public function submitPayment(SubmitPaymentRequest $request, Booking $booking): RedirectResponse
    {
        // See payment(): the per-slot court is loaded up front so a
        // multi-court booking never triggers an N+1 while rendering its slots.
        $booking->loadMissing(['court:id,name,slug', 'slot', 'slots', 'slots.court:id,name']);

        if ($booking->status === Booking::STATUS_PENDING_VERIFICATION) {
            // Double-submitted form. The service is idempotent, but there is no
            // reason to re-upload the screenshot to get there.
            return $this->redirectToStatus($booking);
        }

        if ($booking->status !== Booking::STATUS_AWAITING_PAYMENT) {
            return $this->redirectToStatus($booking);
        }

        // Store the proof only once the booking is known to be in a state that
        // can accept it, so a rejected submission leaves no orphan file.
        $proofPath = $this->storeProof($request);

        try {
            // The method travels with the reference: a reference number means
            // nothing to the staff member verifying it until they know which
            // app to open.
            $this->bookings->submitPayment($booking, $request->reference(), $proofPath, $request->method());
        } catch (BookingException $e) {
            $this->discardProof($proofPath);

            return redirect()
                ->route('public.booking.status', ['booking' => $booking->code])
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('public.booking.status', ['booking' => $booking->code])
            ->with('success', 'Thank you! We have your payment details and our team is verifying them now.');
    }

    /* ===================================================================== */
    /* GET /booking/{code} — the shareable status page                        */
    /* ===================================================================== */

    public function status(Booking $booking): Response
    {
        // See payment(): the per-slot court is loaded up front so a
        // multi-court booking never triggers an N+1 while listing its slots.
        $booking->loadMissing(['court:id,name,slug,photo_path', 'slot', 'slots', 'slots.court:id,name']);

        return Inertia::render('PublicSite/BookingStatus', [
            'booking' => $this->bookingPayload($booking),
            'payment' => $booking->status === Booking::STATUS_AWAITING_PAYMENT
                ? $this->paymentSettings()
                : null,
            'serverTime' => Carbon::now()->toIso8601String(),
        ]);
    }

    /* ===================================================================== */
    /* POST /booking/{code}/cancel — customer changed their mind              */
    /* ===================================================================== */

    public function cancel(Booking $booking): RedirectResponse
    {
        try {
            $this->bookings->cancel($booking);
        } catch (BookingException $e) {
            return redirect()
                ->route('public.booking.status', ['booking' => $booking->code])
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('public.booking.status', ['booking' => $booking->code])
            ->with('info', 'Your booking has been cancelled and the slot is back on the schedule.');
    }

    /* ===================================================================== */
    /* /lookup — "check my booking"                                           */
    /* ===================================================================== */

    public function lookupForm(): Response
    {
        return Inertia::render('PublicSite/Lookup', [
            'codePrefix' => Booking::codePrefix(),
        ]);
    }

    public function lookup(LookupBookingRequest $request): RedirectResponse
    {
        $code = $request->code();

        $exists = Booking::query()->where('code', $code)->exists();

        if (! $exists) {
            // Deliberately identical wording whatever went wrong — a lookup
            // form must not become a code oracle.
            return back()
                ->withInput()
                ->withErrors(['code' => 'We could not find a booking with that code. Please check it and try again.']);
        }

        return redirect()->route('public.booking.status', ['booking' => $code]);
    }

    /* ===================================================================== */
    /* Payloads — hand-built, never a raw model                               */
    /* ===================================================================== */

    /**
     * Everything the public pages are allowed to know about a booking.
     *
     * @return array<string, mixed>
     */
    private function bookingPayload(Booking $booking): array
    {
        /** @var CourtSlot|null $slot */
        $slot = $booking->relationLoaded('slot') ? $booking->getRelation('slot') : null;

        // Reuse BookingService::summary() rather than re-deriving the same
        // date/time formatting a second time here — this project has been
        // bitten more than once by two places disagreeing on the same
        // derived value.
        $summary = $this->bookings->summary($booking);

        $holdExpiresAt = $booking->hold_expires_at;

        return [
            'code' => (string) $booking->code,
            'status' => (string) $booking->status,
            'status_label' => $this->statusLabel((string) $booking->status),
            'step' => $this->stepFor((string) $booking->status),

            'court_name' => (string) ($booking->court?->name ?? 'Court'),
            'court_slug' => $booking->court?->slug,
            'court_photo_url' => $this->publicUrl($booking->court?->photo_path),

            // The primary court above owns the hero and the "back to court"
            // links; these describe the FULL set a booking spans. A booking
            // across two courts must list both, so the guest sees every court
            // they are paying for — sourced from summary() rather than
            // re-derived here so the two can never disagree on the list. A
            // single-court booking has one name and is_multi_court is false,
            // leaving every existing single-court surface untouched.
            'court_names' => $summary['court_names'],
            'is_multi_court' => $summary['is_multi_court'],

            'date' => $summary['date'],
            'date_short' => $summary['date_short'],
            'time_range' => $summary['time_range'],
            'duration_minutes' => $slot?->duration_minutes,
            'starts_at' => $slot?->startsAt()->toIso8601String(),

            // The full multi-slot view — every time this booking spans, in
            // chronological order, plus a single joined string for anything
            // that only has room to interpolate one string.
            'slot_count' => $summary['slot_count'],
            'slots' => $summary['slots'],
            'time_range_full' => $summary['time_range_full'],

            'customer_name' => (string) $booking->customer_name,
            'customer_phone' => $this->maskPhone((string) $booking->customer_phone),
            'customer_email' => $booking->customer_email,
            'notes' => $booking->notes,

            // The authoritative amount: copied from the slot at reserve time and
            // re-read here on every render. The client can never influence it.
            'amount' => (float) $booking->amount,

            'payment_reference' => $booking->payment_reference,
            // Both nullable on purpose: a booking taken before the site
            // offered a choice has no method, and the pages render that as an
            // em dash rather than assuming the reference came from GCash.
            'payment_method' => $booking->payment_method,
            'payment_method_label' => $booking->paymentMethodLabel(),
            'payment_proof_url' => $this->publicUrl($booking->payment_proof_path),
            'payment_submitted_at' => $booking->payment_submitted_at?->toIso8601String(),

            'hold_expires_at' => $holdExpiresAt?->toIso8601String(),
            'hold_seconds_remaining' => $booking->hold_seconds_remaining,
            'hold_expired' => $booking->isHoldExpired(),

            'confirmed_at' => $booking->confirmed_at?->toIso8601String(),
            'rejection_reason' => $booking->rejection_reason,
            'cancelled_at' => $booking->cancelled_at?->toIso8601String(),
            'created_at' => $booking->created_at?->toIso8601String(),

            'can_cancel' => $booking->isCancellable(),
            'can_pay' => $booking->status === Booking::STATUS_AWAITING_PAYMENT && ! $booking->isHoldExpired(),
        ];
    }

    /**
     * Payment details, read through the cached settings repository.
     *
     * `methods` may be empty — that is what drives the "not published yet"
     * panel on the checkout, so the page shows a designed "call us instead"
     * message rather than a broken image. `instructions` and the two support
     * keys stay at the top level because they are shared by every method and
     * because BookingStatus.vue reads the support pair on its own.
     *
     * @return array<string, mixed>
     */
    private function paymentSettings(): array
    {
        return [
            // Derived by PaymentMethodService rather than here, because
            // SubmitPaymentRequest has to reach the same verdict: it only
            // demands a chosen method when this list gave the guest one to
            // choose. Two copies of this rule is exactly how the checkout
            // ended up rejecting a submission it had rendered no control for.
            'methods' => $this->paymentMethods->published(),
            'instructions' => $this->stringSetting('payment', 'payment_instructions'),
            // Prefixed keys — must match what Admin > Settings > Company writes.
            'support_phone' => $this->stringSetting('company', 'company_phone'),
            'support_email' => $this->stringSetting('company', 'company_email'),
        ];
    }

    /* ===================================================================== */
    /* Uploads                                                                */
    /* ===================================================================== */

    /**
     * Persist the screenshot on the public disk, foldered by month so the
     * directory never grows into a million-entry listing.
     */
    private function storeProof(SubmitPaymentRequest $request): ?string
    {
        if (! $request->hasFile('payment_proof')) {
            return null;
        }

        $path = $request->file('payment_proof')?->store(
            self::PROOF_DIRECTORY.'/'.Carbon::now()->format('Y/m'),
            'public',
        );

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function discardProof(?string $path): void
    {
        if ($path === null) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /* ===================================================================== */
    /* Small helpers                                                          */
    /* ===================================================================== */

    private function redirectToStatus(Booking $booking): RedirectResponse
    {
        return redirect()->route('public.booking.status', ['booking' => $booking->code]);
    }

    private function backToSchedule(string $courtSlug, string $message): RedirectResponse
    {
        $target = $courtSlug !== ''
            ? redirect()->route('public.courts.show', ['court' => $courtSlug])
            : redirect()->route('public.courts.index');

        return $target->with('error', $message);
    }

    /**
     * The status page is shareable by design — the customer forwards the link
     * to whoever is playing. Masking keeps the middle of the number private
     * while leaving enough for the owner to recognise it as theirs.
     */
    private function maskPhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        if (strlen($digits) < 7) {
            return $phone;
        }

        return substr($digits, 0, 4).str_repeat('•', max(0, strlen($digits) - 7)).substr($digits, -3);
    }

    /**
     * 1 Pick a slot · 2 Your details · 3 Send payment · 4 Confirmed.
     * A value above the step count renders every step complete.
     */
    private function stepFor(string $status): int
    {
        return match ($status) {
            Booking::STATUS_AWAITING_PAYMENT => 3,
            Booking::STATUS_PENDING_VERIFICATION => 4,
            Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED => 5,
            default => 3,
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            Booking::STATUS_AWAITING_PAYMENT => 'Awaiting payment',
            Booking::STATUS_PENDING_VERIFICATION => 'Pending verification',
            Booking::STATUS_CONFIRMED => 'Confirmed',
            Booking::STATUS_COMPLETED => 'Completed',
            Booking::STATUS_REJECTED => 'Payment not verified',
            Booking::STATUS_CANCELLED => 'Cancelled',
            Booking::STATUS_EXPIRED => 'Expired',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function stringSetting(string $group, string $key): ?string
    {
        $value = $this->settings->get($group, $key);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

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
}
