<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The happy-path chain: reserve -> submitPayment -> confirm, and the two
 * off-ramps (reject, cancel), plus the scheduled expire().
 */
class BookingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    private CourtSlot $slot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BookingService::class);

        $court = Court::factory()->create();
        $this->slot = CourtSlot::factory()
            ->forCourt($court)
            ->onDate(Carbon::today()->addDay())
            ->atHour(9)
            ->available()
            ->create();
    }

    public function test_reserve_places_a_hold_and_flips_the_slot_to_held(): void
    {
        $booking = $this->service->reserve([$this->slot->getKey()], $this->customer());

        self::assertSame(Booking::STATUS_AWAITING_PAYMENT, $booking->status);
        self::assertNotNull($booking->hold_expires_at);
        self::assertEqualsWithDelta(
            now()->addMinutes((int) config('booking.hold_minutes', 30))->getTimestamp(),
            $booking->hold_expires_at->getTimestamp(),
            5,
        );
        self::assertSame((string) $this->slot->price, (string) $booking->amount);

        $this->slot->refresh();
        self::assertSame(CourtSlot::STATUS_HELD, $this->slot->status);
        self::assertSame($booking->getKey(), $this->slot->held_booking_id);
    }

    public function test_submit_payment_moves_to_pending_verification_and_extends_the_hold(): void
    {
        $booking = $this->service->reserve([$this->slot->getKey()], $this->customer());
        $originalHoldExpiry = $booking->hold_expires_at;

        $updated = $this->service->submitPayment(
            $booking,
            '1234567890123',
            null,
            Booking::PAYMENT_METHOD_GOTYME,
        );

        self::assertSame(Booking::STATUS_PENDING_VERIFICATION, $updated->status);
        self::assertSame('1234567890123', $updated->payment_reference);
        // The method travels with the reference and is persisted verbatim —
        // GoTyme rather than GCash so a default-to-the-first-wallet bug cannot
        // pass this assertion by accident.
        self::assertSame(Booking::PAYMENT_METHOD_GOTYME, $updated->payment_method);
        self::assertSame('GoTyme', $updated->paymentMethodLabel());
        self::assertNotNull($updated->payment_submitted_at);
        self::assertTrue($updated->hold_expires_at->greaterThan($originalHoldExpiry));
        self::assertEqualsWithDelta(
            now()->addMinutes((int) config('booking.verification_hold_minutes', 720))->getTimestamp(),
            $updated->hold_expires_at->getTimestamp(),
            5,
        );

        // Slot stays held throughout â€” only confirm() books it permanently.
        $this->slot->refresh();
        self::assertSame(CourtSlot::STATUS_HELD, $this->slot->status);
    }

    public function test_confirm_books_the_slot_permanently(): void
    {
        $admin = User::factory()->create();
        $booking = $this->service->reserve([$this->slot->getKey()], $this->customer());
        $this->service->submitPayment($booking, '1234567890123');

        $confirmed = $this->service->confirm($booking, $admin);

        self::assertSame(Booking::STATUS_CONFIRMED, $confirmed->status);
        self::assertNotNull($confirmed->confirmed_at);
        self::assertSame($admin->getKey(), $confirmed->confirmed_by);
        self::assertNull($confirmed->hold_expires_at);

        $this->slot->refresh();
        self::assertSame(CourtSlot::STATUS_BOOKED, $this->slot->status);
        self::assertSame($confirmed->getKey(), $this->slot->held_booking_id);
    }

    public function test_reject_frees_the_slot_back_to_available(): void
    {
        $admin = User::factory()->create();
        $booking = $this->service->reserve([$this->slot->getKey()], $this->customer());
        $this->service->submitPayment($booking, '1234567890123');

        $rejected = $this->service->reject($booking, $admin, 'Reference not found.');

        self::assertSame(Booking::STATUS_REJECTED, $rejected->status);
        self::assertSame('Reference not found.', $rejected->rejection_reason);
        self::assertNotNull($rejected->rejected_at);
        self::assertNull($rejected->hold_expires_at);

        $this->slot->refresh();
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $this->slot->status);
        self::assertNull($this->slot->held_booking_id);
    }

    public function test_cancel_frees_the_slot(): void
    {
        $booking = $this->service->reserve([$this->slot->getKey()], $this->customer());

        $cancelled = $this->service->cancel($booking);

        self::assertSame(Booking::STATUS_CANCELLED, $cancelled->status);
        self::assertNotNull($cancelled->cancelled_at);

        $this->slot->refresh();
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $this->slot->status);
        self::assertNull($this->slot->held_booking_id);
    }

    public function test_expire_frees_the_slot(): void
    {
        $booking = $this->service->reserve([$this->slot->getKey()], $this->customer());

        // Force the hold clock into the past so expire() finds it lapsed.
        $booking->forceFill(['hold_expires_at' => Carbon::now()->subMinute()])->save();

        $released = $this->service->expire($booking->fresh());

        self::assertTrue($released);

        $booking->refresh();
        self::assertSame(Booking::STATUS_EXPIRED, $booking->status);
        self::assertNull($booking->hold_expires_at);

        $this->slot->refresh();
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $this->slot->status);
        self::assertNull($this->slot->held_booking_id);
    }

    public function test_expire_does_nothing_while_the_hold_has_not_lapsed(): void
    {
        $booking = $this->service->reserve([$this->slot->getKey()], $this->customer());

        $released = $this->service->expire($booking);

        self::assertFalse($released);

        $booking->refresh();
        self::assertSame(Booking::STATUS_AWAITING_PAYMENT, $booking->status);

        $this->slot->refresh();
        self::assertSame(CourtSlot::STATUS_HELD, $this->slot->status);
    }

    /**
     * @return array<string, string>
     */
    private function customer(): array
    {
        return [
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
            'customer_email' => 'juan@example.com',
        ];
    }
}

