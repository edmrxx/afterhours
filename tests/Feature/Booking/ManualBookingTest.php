<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use App\Notifications\BookingReserved;
use App\Services\BookingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The desk keying a booking in for a customer who arranged it directly.
 *
 * Everything asserted here is a place where a manual booking must behave
 * DIFFERENTLY from a guest one, because those are the only places a change
 * could silently regress into the public flow's rules and go unnoticed: the
 * missing hold clock, the reachable past, the confirmable hold, the silence.
 */
class ManualBookingTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    private Court $court;

    private ?User $staff = null;

    private ?User $viewer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->service = app(BookingService::class);
        $this->court = Court::factory()->create();
        $this->staff = null;
        $this->viewer = null;
    }

    /* ------------------------------------------------------------------ */
    /* How it lands                                                        */
    /* ------------------------------------------------------------------ */

    public function test_a_confirmed_manual_booking_sells_the_slot_outright(): void
    {
        $slot = $this->slotAt(9, Carbon::tomorrow());

        $booking = $this->service->reserveManually(
            [$slot->getKey()],
            $this->customer(),
            $this->staff(),
            BookingService::MANUAL_MODE_CONFIRMED,
        );

        self::assertSame(Booking::STATUS_CONFIRMED, $booking->status);
        self::assertSame(Booking::SOURCE_MANUAL, $booking->source);
        self::assertSame(Booking::PAYMENT_METHOD_CASH, $booking->payment_method);
        self::assertSame($this->staff()->getKey(), $booking->created_by);
        self::assertSame($this->staff()->getKey(), $booking->confirmed_by);
        self::assertSame(CourtSlot::STATUS_BOOKED, $slot->refresh()->status);
    }

    public function test_a_reserved_manual_booking_holds_the_slot_with_no_expiry(): void
    {
        $slot = $this->slotAt(9, Carbon::tomorrow());

        $booking = $this->service->reserveManually(
            [$slot->getKey()],
            $this->customer(),
            $this->staff(),
            BookingService::MANUAL_MODE_RESERVED,
        );

        self::assertSame(Booking::STATUS_AWAITING_PAYMENT, $booking->status);
        self::assertSame(CourtSlot::STATUS_HELD, $slot->refresh()->status);

        // The whole point: a court promised to someone must not evaporate on a
        // timer. ReleaseExpiredBookingHolds scans whereNotNull on this column,
        // so null is what keeps the scheduler's hands off it.
        self::assertNull($booking->hold_expires_at);
        self::assertNull($booking->payment_method);
    }

    public function test_the_release_scheduler_never_sees_a_manual_hold(): void
    {
        $slot = $this->slotAt(9, Carbon::tomorrow());

        $this->service->reserveManually(
            [$slot->getKey()],
            $this->customer(),
            $this->staff(),
            BookingService::MANUAL_MODE_RESERVED,
        );

        // Long past the point where a guest's 30-minute hold would have been
        // swept up. Nothing about a desk-made hold expires with time.
        Carbon::setTestNow(Carbon::now()->addDays(3));

        self::assertSame(0, Booking::query()->expiredHolds()->count());

        $this->artisan('bookings:release-holds')->assertExitCode(0);

        self::assertSame(CourtSlot::STATUS_HELD, $slot->refresh()->status);

        Carbon::setTestNow();
    }

    public function test_a_session_that_has_already_finished_is_recorded_as_completed(): void
    {
        $slot = $this->slotAt(9, Carbon::yesterday());

        // Asked for as a reservation — you cannot reserve the past, and the
        // service says so by recording history instead of arguing.
        $booking = $this->service->reserveManually(
            [$slot->getKey()],
            $this->customer(),
            $this->staff(),
            BookingService::MANUAL_MODE_RESERVED,
        );

        self::assertSame(Booking::STATUS_COMPLETED, $booking->status);
        self::assertSame(CourtSlot::STATUS_BOOKED, $slot->refresh()->status);
        self::assertSame(Booking::PAYMENT_METHOD_CASH, $booking->payment_method);
    }

    public function test_a_session_still_running_is_not_treated_as_history(): void
    {
        // 9pm hour started fifteen minutes ago, 10pm hour still to come — a
        // walk-in being keyed in mid-session. Deciding on the FIRST slot's
        // start would misfile this as a finished game.
        Carbon::setTestNow(Carbon::today()->setTime(21, 15));

        $slots = [
            $this->slotAt(21, Carbon::today()),
            $this->slotAt(22, Carbon::today()),
        ];

        $booking = $this->service->reserveManually(
            array_map(static fn (CourtSlot $slot): int => (int) $slot->getKey(), $slots),
            $this->customer(),
            $this->staff(),
            BookingService::MANUAL_MODE_CONFIRMED,
        );

        self::assertSame(Booking::STATUS_CONFIRMED, $booking->status);

        Carbon::setTestNow();
    }

    public function test_the_amount_is_the_sum_of_the_slot_prices(): void
    {
        $slots = [
            $this->slotAt(9, Carbon::tomorrow(), 650),
            $this->slotAt(10, Carbon::tomorrow(), 650),
            $this->slotAt(11, Carbon::tomorrow(), 300),
        ];

        $booking = $this->service->reserveManually(
            array_map(static fn (CourtSlot $slot): int => (int) $slot->getKey(), $slots),
            $this->customer(),
            $this->staff(),
            BookingService::MANUAL_MODE_CONFIRMED,
        );

        self::assertSame('1600.00', (string) $booking->amount);
        self::assertCount(3, $booking->slots);
    }

    /* ------------------------------------------------------------------ */
    /* What it refuses                                                     */
    /* ------------------------------------------------------------------ */

    public function test_a_blocked_slot_is_refused(): void
    {
        $slot = $this->slotAt(9, Carbon::tomorrow());
        $slot->forceFill(['status' => CourtSlot::STATUS_BLOCKED])->save();

        $this->expectException(BookingException::class);

        $this->service->reserveManually(
            [$slot->getKey()],
            $this->customer(),
            $this->staff(),
            BookingService::MANUAL_MODE_CONFIRMED,
        );
    }

    public function test_a_slot_someone_else_already_holds_is_refused_and_nothing_is_written(): void
    {
        $free = $this->slotAt(9, Carbon::tomorrow());
        $taken = $this->slotAt(10, Carbon::tomorrow());

        $this->service->reserve([$taken->getKey()], $this->customer());

        try {
            $this->service->reserveManually(
                [$free->getKey(), $taken->getKey()],
                $this->customer(),
                $this->staff(),
                BookingService::MANUAL_MODE_CONFIRMED,
            );
            self::fail('Expected the clash to be refused.');
        } catch (BookingException) {
            // All-or-nothing: the free hour must not have been quietly taken
            // on the way to failing on the second one.
            self::assertSame(CourtSlot::STATUS_AVAILABLE, $free->refresh()->status);
            self::assertSame(0, Booking::query()->where('source', Booking::SOURCE_MANUAL)->count());
        }
    }

    /* ------------------------------------------------------------------ */
    /* Silence                                                             */
    /* ------------------------------------------------------------------ */

    public function test_nothing_is_emailed_for_a_manual_booking(): void
    {
        Notification::fake();

        $slot = $this->slotAt(9, Carbon::tomorrow());

        $booking = $this->service->reserveManually(
            [$slot->getKey()],
            $this->customer(),
            $this->staff(),
            BookingService::MANUAL_MODE_RESERVED,
        );

        Notification::assertNothingSent();

        // And still nothing when the payment finally arrives and staff close
        // it out — the gate is on the booking, not on the one transition.
        $this->service->confirm($booking->refresh(), $this->staff());

        Notification::assertNotSentTo(
            Notification::route('mail', 'edd@example.test'),
            BookingConfirmed::class,
        );
        Notification::assertNothingSent();
    }

    public function test_a_public_booking_still_notifies(): void
    {
        Notification::fake();

        $slot = $this->slotAt(9, Carbon::tomorrow());
        $this->service->reserve([$slot->getKey()], $this->customer());

        Notification::assertSentOnDemand(BookingReserved::class);
    }

    /* ------------------------------------------------------------------ */
    /* Confirming a desk-made hold                                         */
    /* ------------------------------------------------------------------ */

    public function test_a_manual_hold_can_be_confirmed_straight_from_awaiting_payment(): void
    {
        $slot = $this->slotAt(9, Carbon::tomorrow());

        $booking = $this->service->reserveManually(
            [$slot->getKey()],
            $this->customer(),
            $this->staff(),
            BookingService::MANUAL_MODE_RESERVED,
        );

        $confirmed = $this->service->confirm($booking->refresh(), $this->staff());

        self::assertSame(Booking::STATUS_CONFIRMED, $confirmed->status);
        self::assertSame(CourtSlot::STATUS_BOOKED, $slot->refresh()->status);
    }

    public function test_a_public_booking_still_cannot_skip_verification(): void
    {
        $slot = $this->slotAt(9, Carbon::tomorrow());
        $booking = $this->service->reserve([$slot->getKey()], $this->customer());

        // The guarantee the manual path must not have loosened: confirming a
        // guest booking nobody has checked would be signing off on a payment
        // that was never seen.
        $this->expectException(BookingException::class);

        $this->service->confirm($booking, $this->staff());
    }

    /* ------------------------------------------------------------------ */
    /* Revenue                                                             */
    /* ------------------------------------------------------------------ */

    public function test_a_settled_manual_booking_counts_towards_revenue(): void
    {
        $confirmed = $this->service->reserveManually(
            [$this->slotAt(9, Carbon::tomorrow(), 650)->getKey()],
            $this->customer(),
            $this->staff(),
            BookingService::MANUAL_MODE_CONFIRMED,
        );

        $held = $this->service->reserveManually(
            [$this->slotAt(10, Carbon::tomorrow(), 650)->getKey()],
            $this->customer(),
            $this->staff(),
            BookingService::MANUAL_MODE_RESERVED,
        );

        $revenue = Booking::query()->revenueBearing()->pluck('id')->all();

        self::assertContains($confirmed->getKey(), $revenue);
        // Nothing has been collected on a hold, so it must not be counted yet.
        self::assertNotContains($held->getKey(), $revenue);
    }

    /* ------------------------------------------------------------------ */
    /* The screen                                                          */
    /* ------------------------------------------------------------------ */

    public function test_staff_can_open_the_form_and_post_a_booking(): void
    {
        $slot = $this->slotAt(9, Carbon::tomorrow());

        $this->actingAs($this->staff())
            ->get(route('admin.bookings.create'))
            ->assertOk();

        $this->actingAs($this->staff())
            ->post(route('admin.bookings.store'), [
                'slot_ids' => [$slot->getKey()],
                'mode' => BookingService::MANUAL_MODE_CONFIRMED,
                'customer_name' => 'Edd Mark',
                'customer_phone' => '0917 123 4567',
            ])
            ->assertRedirect();

        $booking = Booking::query()->where('source', Booking::SOURCE_MANUAL)->firstOrFail();

        self::assertSame(Booking::STATUS_CONFIRMED, $booking->status);
        // Typed with spaces, stored in the one canonical form — so a search for
        // this customer finds their public bookings too.
        self::assertSame('09171234567', $booking->customer_phone);
        self::assertNull($booking->customer_email);
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $slot = $this->slotAt(9, Carbon::tomorrow());

        $this->actingAs($this->viewer())
            ->get(route('admin.bookings.create'))
            ->assertForbidden();

        $this->actingAs($this->viewer())
            ->post(route('admin.bookings.store'), [
                'slot_ids' => [$slot->getKey()],
                'mode' => BookingService::MANUAL_MODE_CONFIRMED,
                'customer_name' => 'Edd Mark',
                'customer_phone' => '09171234567',
            ])
            ->assertForbidden();

        self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->refresh()->status);
    }

    public function test_the_form_rejects_a_blocked_slot_with_a_readable_error(): void
    {
        $slot = $this->slotAt(9, Carbon::tomorrow());
        $slot->forceFill(['status' => CourtSlot::STATUS_BLOCKED])->save();

        $this->actingAs($this->staff())
            ->post(route('admin.bookings.store'), [
                'slot_ids' => [$slot->getKey()],
                'mode' => BookingService::MANUAL_MODE_CONFIRMED,
                'customer_name' => 'Edd Mark',
                'customer_phone' => '09171234567',
            ])
            ->assertSessionHasErrors('slot_ids.0');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function slotAt(int $hour, Carbon $date, ?int $price = null): CourtSlot
    {
        $slot = CourtSlot::factory()
            ->forCourt($this->court)
            ->onDate($date)
            ->atHour($hour)
            ->available()
            ->create();

        if ($price !== null) {
            $slot->forceFill(['price' => $price])->save();
        }

        return $slot;
    }

    private function staff(): User
    {
        return $this->staff ??= tap(
            User::factory()->create(['must_change_password' => false]),
            static fn (User $user) => $user->assignRole(RolePermissionSeeder::ROLE_STAFF),
        );
    }

    /** Can read the queue, may not add to it. */
    private function viewer(): User
    {
        return $this->viewer ??= tap(
            User::factory()->create(['must_change_password' => false]),
            static fn (User $user) => $user->givePermissionTo('bookings.view'),
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
