<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Deleting a booking must never leave an hour unsellable.
 *
 * The regression these tests pin: BookingController::destroy() used to free
 * slots only when the booking was still `isHolding()`. confirm() promotes a
 * booking's slots to `booked` and complete() deliberately leaves them there,
 * so deleting a confirmed booking soft-deleted the row and stranded every one
 * of its slots at `booked` with a `held_booking_id` pointing at a booking that
 * no longer existed — gone from the admin list, still showing "Booked" on the
 * public grid, with nothing left on screen to explain why. That is silent lost
 * revenue: the hours never come back on sale on their own.
 */
class BookingDeletionReleasesSlotsTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    private Court $court;

    private ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->service = app(BookingService::class);
        $this->court = Court::factory()->create();
        $this->admin = null;
    }

    public function test_deleting_a_confirmed_booking_returns_its_slot_to_available(): void
    {
        $slot = $this->slotAt(9);
        $booking = $this->confirmedBooking([$slot]);

        self::assertSame(CourtSlot::STATUS_BOOKED, $slot->refresh()->status);

        $this->actingAs($this->admin())
            ->delete(route('admin.bookings.destroy', $booking))
            ->assertRedirect();

        $slot->refresh();
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
        self::assertNull($slot->held_booking_id);
        self::assertSoftDeleted($booking);
    }

    public function test_deleting_a_confirmed_booking_frees_every_slot_not_just_the_primary(): void
    {
        $slots = [$this->slotAt(9), $this->slotAt(10), $this->slotAt(11)];
        $booking = $this->confirmedBooking($slots);

        $this->actingAs($this->admin())
            ->delete(route('admin.bookings.destroy', $booking))
            ->assertRedirect();

        // Slots 2..n live only in the booking_slots pivot — a release keyed on
        // bookings.court_slot_id alone would quietly abandon them.
        foreach ($slots as $slot) {
            $slot->refresh();
            self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
            self::assertNull($slot->held_booking_id);
        }
    }

    public function test_deleting_a_completed_booking_returns_its_slot_to_available(): void
    {
        $slot = $this->slotAt(9);
        $booking = $this->confirmedBooking([$slot]);
        $this->service->complete($booking, $this->admin());

        // complete() leaves the slot `booked` on purpose — the game happened.
        self::assertSame(CourtSlot::STATUS_BOOKED, $slot->refresh()->status);

        $this->actingAs($this->admin())
            ->delete(route('admin.bookings.destroy', $booking))
            ->assertRedirect();

        $slot->refresh();
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
        self::assertNull($slot->held_booking_id);
    }

    public function test_deleting_a_booking_still_on_hold_keeps_freeing_its_slot(): void
    {
        $slot = $this->slotAt(9);
        $booking = $this->service->reserve([$slot->getKey()], $this->customer());

        self::assertSame(CourtSlot::STATUS_HELD, $slot->refresh()->status);

        $this->actingAs($this->admin())
            ->delete(route('admin.bookings.destroy', $booking))
            ->assertRedirect();

        $slot->refresh();
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
        self::assertNull($slot->held_booking_id);
    }

    public function test_a_blocked_slot_is_not_put_back_on_sale_by_the_delete(): void
    {
        $slot = $this->slotAt(9);
        $booking = $this->confirmedBooking([$slot]);

        // Staff take the hour off the market after the booking was confirmed —
        // a court closure, maintenance. Deleting the booking is no reason to
        // start selling it again.
        $slot->forceFill(['status' => CourtSlot::STATUS_BLOCKED])->save();

        $this->actingAs($this->admin())
            ->delete(route('admin.bookings.destroy', $booking))
            ->assertRedirect();

        $slot->refresh();
        self::assertSame(CourtSlot::STATUS_BLOCKED, $slot->status);
        // The pointer still goes: after the delete it refers to nothing.
        self::assertNull($slot->held_booking_id);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function slotAt(int $hour): CourtSlot
    {
        return CourtSlot::factory()
            ->forCourt($this->court)
            ->onDate(Carbon::today()->addDay())
            ->atHour($hour)
            ->available()
            ->create();
    }

    /**
     * Drive a booking all the way to `confirmed` through the service, so its
     * slots reach `booked` exactly the way production gets them there.
     *
     * @param  list<CourtSlot>  $slots
     */
    private function confirmedBooking(array $slots): Booking
    {
        $booking = $this->service->reserve(
            array_map(static fn (CourtSlot $slot): int => (int) $slot->getKey(), $slots),
            $this->customer(),
        );

        $this->service->submitPayment($booking, '1234567890123', null, Booking::PAYMENT_METHOD_BDO);

        return $this->service->confirm($booking->refresh(), $this->admin());
    }

    private function admin(): User
    {
        // Memoised per test, never static: RefreshDatabase truncates between
        // tests, so a cached User carried across them would reference a row
        // that no longer exists.
        return $this->admin ??= tap(
            User::factory()->create(['must_change_password' => false]),
            static fn (User $user) => $user->assignRole(RolePermissionSeeder::ROLE_ADMIN),
        );
    }

    /** @return array<string, string> */
    private function customer(): array
    {
        return [
            'name' => 'Edd Mark',
            'phone' => '09171234567',
            'email' => 'edd@example.test',
        ];
    }
}
