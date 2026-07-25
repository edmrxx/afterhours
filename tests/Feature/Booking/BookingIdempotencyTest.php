<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Every transition must tolerate being called twice — double-clicked admin
 * buttons and retried release-job passes are normal traffic, not exceptions.
 *
 * The most dangerous failure mode this guards against: expire() resurrecting
 * itself against a booking a human already confirmed and got paid for.
 */
class BookingIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BookingService::class);
    }

    public function test_confirm_called_twice_does_not_double_apply(): void
    {
        $admin = User::factory()->create();
        $slot = $this->availableSlot();
        $booking = $this->service->reserve([$slot->getKey()], $this->customer());
        $this->service->submitPayment($booking, '1234567890123');

        $first = $this->service->confirm($booking, $admin);
        $firstConfirmedAt = $first->confirmed_at;

        // Second admin double-clicks, or a retried request replays.
        $second = $this->service->confirm($booking->fresh(), $admin);

        self::assertSame(Booking::STATUS_CONFIRMED, $second->status);
        self::assertTrue($firstConfirmedAt->equalTo($second->confirmed_at), 'confirmed_at must not be re-stamped.');

        // Only one audit "Confirmed booking" entry, not two.
        $confirmCount = \App\Models\AuditTrail::query()
            ->where('description', 'like', 'Confirmed booking%')
            ->count();
        self::assertSame(1, $confirmCount);

        $slot->refresh();
        self::assertSame(CourtSlot::STATUS_BOOKED, $slot->status);
    }

    public function test_expire_does_not_free_a_confirmed_and_paid_slot(): void
    {
        $admin = User::factory()->create();
        $slot = $this->availableSlot();
        $booking = $this->service->reserve([$slot->getKey()], $this->customer());
        $this->service->submitPayment($booking, '1234567890123');
        $confirmed = $this->service->confirm($booking, $admin);

        // Simulate the release job picking up a row it should never touch.
        $released = $this->service->expire($confirmed->fresh());

        self::assertFalse($released, 'expire() must refuse a confirmed booking.');

        $confirmed->refresh();
        self::assertSame(Booking::STATUS_CONFIRMED, $confirmed->status);

        $slot->refresh();
        self::assertSame(CourtSlot::STATUS_BOOKED, $slot->status, 'A paid slot must never be freed by the release job.');
        self::assertSame($confirmed->getKey(), $slot->held_booking_id);
    }

    public function test_reject_called_twice_is_a_no_op_the_second_time(): void
    {
        $admin = User::factory()->create();
        $slot = $this->availableSlot();
        $booking = $this->service->reserve([$slot->getKey()], $this->customer());
        $this->service->submitPayment($booking, '1234567890123');

        $first = $this->service->reject($booking, $admin, 'No match.');
        $firstRejectedAt = $first->rejected_at;

        $second = $this->service->reject($booking->fresh(), $admin, 'A different reason this time.');

        self::assertSame(Booking::STATUS_REJECTED, $second->status);
        self::assertTrue($firstRejectedAt->equalTo($second->rejected_at), 'rejected_at must not be re-stamped.');
        self::assertSame('No match.', $second->rejection_reason, 'The second call must not overwrite the original reason.');

        $slot->refresh();
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
    }

    public function test_cancel_called_twice_is_a_no_op_the_second_time(): void
    {
        $slot = $this->availableSlot();
        $booking = $this->service->reserve([$slot->getKey()], $this->customer());

        $first = $this->service->cancel($booking);
        $second = $this->service->cancel($booking->fresh());

        self::assertSame(Booking::STATUS_CANCELLED, $second->status);
        self::assertTrue($first->cancelled_at->equalTo($second->cancelled_at));

        $slot->refresh();
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
    }

    public function test_submit_payment_called_twice_does_not_re_notify_or_change_state(): void
    {
        $slot = $this->availableSlot();
        $booking = $this->service->reserve([$slot->getKey()], $this->customer());

        $first = $this->service->submitPayment($booking, '1111111111111');
        $second = $this->service->submitPayment($first->fresh(), '2222222222222');

        self::assertSame(Booking::STATUS_PENDING_VERIFICATION, $second->status);
        // Idempotent guard returns the booking untouched — the second
        // reference must never overwrite the first.
        self::assertSame('1111111111111', $second->payment_reference);
    }

    public function test_confirm_rejects_a_booking_that_was_never_submitted_for_verification(): void
    {
        $admin = User::factory()->create();
        $slot = $this->availableSlot();
        $booking = $this->service->reserve([$slot->getKey()], $this->customer());

        $this->expectException(BookingException::class);

        $this->service->confirm($booking, $admin);
    }

    public function test_reject_rejects_a_booking_that_is_already_confirmed(): void
    {
        $admin = User::factory()->create();
        $slot = $this->availableSlot();
        $booking = $this->service->reserve([$slot->getKey()], $this->customer());
        $this->service->submitPayment($booking, '1234567890123');
        $confirmed = $this->service->confirm($booking, $admin);

        $this->expectException(BookingException::class);

        $this->service->reject($confirmed, $admin, 'Too late.');
    }

    private function availableSlot(): CourtSlot
    {
        $court = Court::factory()->create();

        return CourtSlot::factory()
            ->forCourt($court)
            ->onDate(Carbon::today()->addDay())
            ->atHour(9)
            ->available()
            ->create();
    }

    /**
     * @return array<string, string>
     */
    private function customer(): array
    {
        return [
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
        ];
    }
}
