<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `bookings:release-holds` — the scheduled sweep behind ReleaseExpiredBookingHolds.
 */
class ReleaseExpiredBookingHoldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_expires_only_genuinely_stale_holds(): void
    {
        $staleSlot = $this->makeSlot();
        $staleBooking = Booking::factory()
            ->forSlot($staleSlot)
            ->staleHold()
            ->create();
        $staleSlot->update(['status' => CourtSlot::STATUS_HELD, 'held_booking_id' => $staleBooking->getKey()]);

        $freshSlot = $this->makeSlot();
        $freshBooking = Booking::factory()
            ->forSlot($freshSlot)
            ->awaitingPayment()
            ->create();
        $freshSlot->update(['status' => CourtSlot::STATUS_HELD, 'held_booking_id' => $freshBooking->getKey()]);

        $this->artisan('bookings:release-holds')->assertExitCode(0);

        $staleBooking->refresh();
        self::assertSame(Booking::STATUS_EXPIRED, $staleBooking->status);
        self::assertNull($staleBooking->hold_expires_at);

        $staleSlot->refresh();
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $staleSlot->status);
        self::assertNull($staleSlot->held_booking_id);

        // The still-fresh hold must be completely untouched.
        $freshBooking->refresh();
        self::assertSame(Booking::STATUS_AWAITING_PAYMENT, $freshBooking->status);

        $freshSlot->refresh();
        self::assertSame(CourtSlot::STATUS_HELD, $freshSlot->status);
    }

    public function test_it_leaves_confirmed_bookings_completely_untouched(): void
    {
        $admin = User::factory()->create();
        $slot = $this->makeSlot();
        $booking = app(BookingService::class)->reserve([$slot->getKey()], $this->customer());
        app(BookingService::class)->submitPayment($booking, '1234567890123');
        $confirmed = app(BookingService::class)->confirm($booking, $admin);

        $this->artisan('bookings:release-holds')->assertExitCode(0);

        $confirmed->refresh();
        self::assertSame(Booking::STATUS_CONFIRMED, $confirmed->status);

        $slot->refresh();
        self::assertSame(CourtSlot::STATUS_BOOKED, $slot->status);
    }

    public function test_it_is_safe_to_run_twice(): void
    {
        $slot = $this->makeSlot();
        $booking = Booking::factory()->forSlot($slot)->staleHold()->create();
        $slot->update(['status' => CourtSlot::STATUS_HELD, 'held_booking_id' => $booking->getKey()]);

        $this->artisan('bookings:release-holds')->assertExitCode(0);
        $this->artisan('bookings:release-holds')->assertExitCode(0);

        $booking->refresh();
        self::assertSame(Booking::STATUS_EXPIRED, $booking->status);

        $slot->refresh();
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
    }

    public function test_it_frees_exactly_the_right_slots_among_several(): void
    {
        $toExpireSlot = $this->makeSlot();
        $toExpire = Booking::factory()->forSlot($toExpireSlot)->staleHold()->create();
        $toExpireSlot->update(['status' => CourtSlot::STATUS_HELD, 'held_booking_id' => $toExpire->getKey()]);

        $untouchedSlot = $this->makeSlot();
        $untouched = Booking::factory()->forSlot($untouchedSlot)->awaitingPayment()->create();
        $untouchedSlot->update(['status' => CourtSlot::STATUS_HELD, 'held_booking_id' => $untouched->getKey()]);

        $blockedSlot = $this->makeSlot();
        $blockedSlot->update(['status' => CourtSlot::STATUS_BLOCKED]);

        $this->artisan('bookings:release-holds')->assertExitCode(0);

        self::assertSame(CourtSlot::STATUS_AVAILABLE, $toExpireSlot->fresh()->status);
        self::assertSame(CourtSlot::STATUS_HELD, $untouchedSlot->fresh()->status);
        self::assertSame(CourtSlot::STATUS_BLOCKED, $blockedSlot->fresh()->status);
    }

    public function test_dry_run_reports_without_changing_anything(): void
    {
        $slot = $this->makeSlot();
        $booking = Booking::factory()->forSlot($slot)->staleHold()->create();
        $slot->update(['status' => CourtSlot::STATUS_HELD, 'held_booking_id' => $booking->getKey()]);

        $this->artisan('bookings:release-holds', ['--dry-run' => true])->assertExitCode(0);

        $booking->refresh();
        self::assertNotSame(Booking::STATUS_EXPIRED, $booking->status);

        $slot->refresh();
        self::assertSame(CourtSlot::STATUS_HELD, $slot->status);
    }

    private function makeSlot(): CourtSlot
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
