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
 * reserve()'s multi-slot extension: several court_slot ids, one or several
 * courts, possibly non-contiguous, collapsed into ONE booking with one summed
 * price. The property that matters most throughout this file is
 * all-or-nothing: a rejected multi-slot request must leave zero side effects
 * on any of the requested slots — no partial holds, ever.
 */
class BookingMultiSlotReservationTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    private Court $court;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BookingService::class);
        $this->court = Court::factory()->create();
    }

    /* ===================================================================== */
    /* 1. reserve() — the happy path                                         */
    /* ===================================================================== */

    public function test_reserve_holds_three_non_contiguous_slots_as_one_booking(): void
    {
        // Created out of chronological order on purpose: the evening slot is
        // written first and so owns the LOWEST id, while the morning slot
        // (created last) owns the HIGHEST id. If court_slot_id were ever
        // resolved by id instead of by (slot_date, start_time), this test
        // would catch it immediately.
        $evening = $this->makeSlot(18, 500.00);
        $morning = $this->makeSlot(8, 300.00);
        $midday = $this->makeSlot(12, 400.00);

        $booking = $this->service->reserve(
            [$evening->getKey(), $morning->getKey(), $midday->getKey()],
            $this->customer(),
        );

        self::assertSame('1200.00', (string) $booking->amount);
        self::assertSame($morning->getKey(), $booking->court_slot_id);

        self::assertDatabaseHas('bookings', [
            'id' => $booking->getKey(),
            'court_slot_id' => $morning->getKey(),
            'amount' => '1200.00',
        ]);

        foreach ([$evening, $morning, $midday] as $slot) {
            $slot->refresh();
            self::assertSame(CourtSlot::STATUS_HELD, $slot->status);
            self::assertSame($booking->getKey(), $slot->held_booking_id);
        }
    }

    /* ===================================================================== */
    /* 2. reserve() across two courts — ONE combined booking                  */
    /* ===================================================================== */

    public function test_reserve_across_two_courts_holds_every_slot_as_one_combined_booking(): void
    {
        // Cross-court is now a first-class case: a guest may pick Court A at 9
        // and Court B at 10 in a single booking, paid and held together. The
        // old mixed-court guard is gone; the booking simply spans both courts.
        $slotA = $this->makeSlot(9, 300.00);

        $otherCourt = Court::factory()->create();
        $slotB = CourtSlot::factory()
            ->forCourt($otherCourt)
            ->onDate(Carbon::today()->addDay())
            ->atHour(10)
            ->available()
            ->create(['price' => 350.00]);

        $booking = $this->service->reserve([$slotA->getKey(), $slotB->getKey()], $this->customer());

        // One booking, one summed price across both courts.
        self::assertSame('650.00', (string) $booking->amount);
        self::assertSame(1, Booking::query()->count(), 'A cross-court request must produce exactly one booking.');

        // The primary FK columns resolve to the earliest (chronological) slot's
        // court — a backward-compatible representative, not the whole story.
        self::assertSame($slotA->getKey(), $booking->court_slot_id);
        self::assertSame($this->court->getKey(), $booking->court_id);

        // Both slots are held for this one booking, regardless of court.
        foreach ([$slotA, $slotB] as $slot) {
            $slot->refresh();
            self::assertSame(CourtSlot::STATUS_HELD, $slot->status);
            self::assertSame($booking->getKey(), $slot->held_booking_id);
        }

        // The read model describes every court the booking spans.
        $summary = $this->service->summary($booking);
        self::assertTrue($summary['is_multi_court']);
        self::assertSame([$this->court->name, $otherCourt->name], $summary['court_names']);
        self::assertSame(2, $summary['slot_count']);
        self::assertSame(
            [$this->court->name, $otherCourt->name],
            array_column($summary['slots'], 'court_name'),
        );
    }

    /* ===================================================================== */
    /* 3. reserve() with one slot already unavailable — the crown jewel      */
    /* ===================================================================== */

    public function test_reserve_refuses_when_one_requested_slot_is_already_held_and_leaves_the_others_available(): void
    {
        $slotA = $this->makeSlot(8, 300.00);
        $slotB = $this->makeSlot(9, 300.00);
        $slotC = $this->makeSlot(10, 300.00);

        // Somebody else already claimed the middle slot.
        $otherBooking = $this->service->reserve([$slotB->getKey()], [
            'customer_name' => 'Other Guest',
            'customer_phone' => '09170000000',
        ]);

        try {
            $this->service->reserve(
                [$slotA->getKey(), $slotB->getKey(), $slotC->getKey()],
                $this->customer(),
            );
            self::fail('Expected a BookingException because one of the requested slots was already held.');
        } catch (BookingException $e) {
            self::assertSame('slot_unavailable', $e->reason());
        }

        // The two slots that were free before this request must still be
        // free afterwards — not left dangling in a held state because they
        // happened to be locked alongside the one that failed.
        $slotA->refresh();
        $slotC->refresh();
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $slotA->status);
        self::assertNull($slotA->held_booking_id);
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $slotC->status);
        self::assertNull($slotC->held_booking_id);

        // The slot that was legitimately held before this request stays
        // exactly as it was — still the FIRST booking's, not reassigned.
        $slotB->refresh();
        self::assertSame(CourtSlot::STATUS_HELD, $slotB->status);
        self::assertSame($otherBooking->getKey(), $slotB->held_booking_id);

        self::assertSame(1, Booking::query()->count(), 'The rejected request must not have created a second booking.');
    }

    /* ===================================================================== */
    /* 4. confirm() — every slot, not just the primary                       */
    /* ===================================================================== */

    public function test_confirm_books_every_slot_of_a_multi_slot_booking(): void
    {
        $admin = User::factory()->create();
        $slots = $this->makeSlots([8, 9, 14]);

        $booking = $this->service->reserve($this->ids($slots), $this->customer());
        $this->service->submitPayment($booking, '1234567890123');

        $confirmed = $this->service->confirm($booking->fresh(), $admin);

        self::assertSame(Booking::STATUS_CONFIRMED, $confirmed->status);

        foreach ($slots as $slot) {
            $slot->refresh();
            self::assertSame(CourtSlot::STATUS_BOOKED, $slot->status);
            self::assertSame($confirmed->getKey(), $slot->held_booking_id);

            self::assertDatabaseHas('court_slots', [
                'id' => $slot->getKey(),
                'status' => CourtSlot::STATUS_BOOKED,
                'held_booking_id' => $confirmed->getKey(),
            ]);
        }
    }

    /* ===================================================================== */
    /* 5. reject() / cancel() — every slot, and only what still belongs      */
    /*    to the booking                                                     */
    /* ===================================================================== */

    public function test_reject_releases_every_slot_of_a_multi_slot_booking(): void
    {
        $admin = User::factory()->create();
        $slots = $this->makeSlots([8, 9, 14]);

        $booking = $this->service->reserve($this->ids($slots), $this->customer());
        $this->service->submitPayment($booking, '1234567890123');

        $rejected = $this->service->reject($booking->fresh(), $admin, 'No match.');

        self::assertSame(Booking::STATUS_REJECTED, $rejected->status);

        foreach ($slots as $slot) {
            $slot->refresh();
            self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
            self::assertNull($slot->held_booking_id);
        }
    }

    public function test_reject_leaves_a_slot_alone_if_another_process_already_changed_its_status(): void
    {
        $admin = User::factory()->create();
        $slots = $this->makeSlots([8, 9, 14]);

        $booking = $this->service->reserve($this->ids($slots), $this->customer());
        $this->service->submitPayment($booking, '1234567890123');

        // Simulate an admin blocking the middle slot directly, out from
        // under the hold, without going through the booking state machine.
        // held_booking_id is left exactly as it was.
        $tampered = $slots[1];
        $tampered->forceFill(['status' => CourtSlot::STATUS_BLOCKED])->save();

        $rejected = $this->service->reject($booking->fresh(), $admin, 'No match.');

        self::assertSame(Booking::STATUS_REJECTED, $rejected->status);

        // A slot that is no longer merely "held" must never be quietly
        // reopened by reject() — it is left exactly as the other process set it.
        $tampered->refresh();
        self::assertSame(CourtSlot::STATUS_BLOCKED, $tampered->status);
        self::assertSame($booking->getKey(), $tampered->held_booking_id);

        foreach ([$slots[0], $slots[2]] as $slot) {
            $slot->refresh();
            self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
            self::assertNull($slot->held_booking_id);
        }
    }

    public function test_cancel_releases_every_slot_of_a_multi_slot_booking(): void
    {
        $slots = $this->makeSlots([8, 9, 14]);

        $booking = $this->service->reserve($this->ids($slots), $this->customer());

        $cancelled = $this->service->cancel($booking);

        self::assertSame(Booking::STATUS_CANCELLED, $cancelled->status);

        foreach ($slots as $slot) {
            $slot->refresh();
            self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
            self::assertNull($slot->held_booking_id);
        }
    }

    public function test_cancel_leaves_a_slot_alone_once_its_hold_belongs_to_another_booking(): void
    {
        $slots = $this->makeSlots([8, 9, 14]);
        $booking = $this->service->reserve($this->ids($slots), $this->customer());

        // A second, unrelated booking exists elsewhere...
        $otherSlot = $this->makeSlot(20, 300.00);
        $otherBooking = $this->service->reserve([$otherSlot->getKey()], [
            'customer_name' => 'Other Guest',
            'customer_phone' => '09170000000',
        ]);

        // ...and — simulating an out-of-band data repair / reassignment — the
        // middle slot's hold is reassigned to that other booking without
        // going through the state machine. releaseSlotsFor() only ever
        // touches slots still held by the booking whose hold is being torn
        // down: this slot must be left exactly alone.
        $reassigned = $slots[1];
        $reassigned->forceFill(['held_booking_id' => $otherBooking->getKey()])->save();

        $cancelled = $this->service->cancel($booking->fresh());

        self::assertSame(Booking::STATUS_CANCELLED, $cancelled->status);

        $reassigned->refresh();
        self::assertSame(CourtSlot::STATUS_HELD, $reassigned->status);
        self::assertSame($otherBooking->getKey(), $reassigned->held_booking_id);

        foreach ([$slots[0], $slots[2]] as $slot) {
            $slot->refresh();
            self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
            self::assertNull($slot->held_booking_id);
        }
    }

    /* ===================================================================== */
    /* 6. expire() — every slot                                              */
    /* ===================================================================== */

    public function test_expire_releases_every_slot_of_a_multi_slot_booking(): void
    {
        $slots = $this->makeSlots([8, 9, 14]);
        $booking = $this->service->reserve($this->ids($slots), $this->customer());

        // Force the hold clock into the past so expire() finds it lapsed.
        $booking->forceFill(['hold_expires_at' => Carbon::now()->subMinute()])->save();

        $released = $this->service->expire($booking->fresh());

        self::assertTrue($released);

        $booking->refresh();
        self::assertSame(Booking::STATUS_EXPIRED, $booking->status);

        foreach ($slots as $slot) {
            $slot->refresh();
            self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
            self::assertNull($slot->held_booking_id);
        }
    }

    /* ===================================================================== */
    /* 7. summary() — the flattened, presentation-ready view                 */
    /* ===================================================================== */

    public function test_summary_describes_every_slot_in_chronological_order_with_the_full_time_range(): void
    {
        // Same out-of-chronological-order creation trick as test #1, so the
        // slots array can only be correct if it is sorted by time, not id.
        $evening = $this->makeSlot(18, 500.00);
        $morning = $this->makeSlot(8, 300.00);
        $midday = $this->makeSlot(12, 400.00);

        $booking = $this->service->reserve(
            [$evening->getKey(), $morning->getKey(), $midday->getKey()],
            $this->customer(),
        );

        $summary = $this->service->summary($booking);

        self::assertSame(3, $summary['slot_count']);
        self::assertCount(3, $summary['slots']);

        $expectedOrder = [$morning->time_range, $midday->time_range, $evening->time_range];
        $actualOrder = array_column($summary['slots'], 'time_range');
        self::assertSame($expectedOrder, $actualOrder);

        foreach ($expectedOrder as $range) {
            self::assertStringContainsString($range, $summary['time_range_full']);
        }
    }

    /* ===================================================================== */
    /* 7b. summary() must survive release — the durable-history regression   */
    /*     guard. reject()/cancel()/expire() null held_booking_id on every    */
    /*     slot to put it back on sale; summary()/Booking::slots() must NOT   */
    /*     go blank the instant that happens, or a rejection notice/status    */
    /*     page would describe a multi-slot booking with zero slots while    */
    /*     still showing its full original amount.                          */
    /* ===================================================================== */

    public function test_summary_still_describes_every_slot_after_a_multi_slot_booking_is_rejected(): void
    {
        $admin = User::factory()->create();
        $evening = $this->makeSlot(18, 500.00);
        $morning = $this->makeSlot(8, 300.00);
        $midday = $this->makeSlot(12, 400.00);

        $booking = $this->service->reserve(
            [$evening->getKey(), $morning->getKey(), $midday->getKey()],
            $this->customer(),
        );
        $this->service->submitPayment($booking, '1234567890123');
        $rejected = $this->service->reject($booking->fresh(), $admin, 'No match.');

        // The live pointer is gone — this is exactly what would have made
        // the old held_booking_id-based relation return empty.
        foreach ([$evening, $morning, $midday] as $slot) {
            $slot->refresh();
            self::assertNull($slot->held_booking_id);
        }

        $summary = $this->service->summary($rejected->fresh());

        self::assertSame(3, $summary['slot_count'], 'A rejected booking must still report every slot it originally held.');
        self::assertCount(3, $summary['slots']);

        $expectedOrder = [$morning->time_range, $midday->time_range, $evening->time_range];
        self::assertSame($expectedOrder, array_column($summary['slots'], 'time_range'));

        foreach ($expectedOrder as $range) {
            self::assertStringContainsString($range, $summary['time_range_full']);
        }

        self::assertCount(3, $rejected->fresh()->slots, 'Booking::slots() itself must survive release, not just summary().');
    }

    public function test_summary_still_describes_every_slot_after_a_multi_slot_booking_is_cancelled(): void
    {
        $slots = $this->makeSlots([8, 9, 14]);
        $booking = $this->service->reserve($this->ids($slots), $this->customer());

        $cancelled = $this->service->cancel($booking);

        $summary = $this->service->summary($cancelled->fresh());
        self::assertSame(3, $summary['slot_count']);
        self::assertCount(3, $summary['slots']);
    }

    public function test_summary_still_describes_every_slot_after_a_multi_slot_booking_expires(): void
    {
        $slots = $this->makeSlots([8, 9, 14]);
        $booking = $this->service->reserve($this->ids($slots), $this->customer());
        $booking->forceFill(['hold_expires_at' => Carbon::now()->subMinute()])->save();

        $this->service->expire($booking->fresh());

        $summary = $this->service->summary($booking->fresh());
        self::assertSame(3, $summary['slot_count']);
        self::assertCount(3, $summary['slots']);
    }

    /* ===================================================================== */
    /* 8. Regression guard — a single-id array behaves like the old single   */
    /*    slot call in every respect                                         */
    /* ===================================================================== */

    public function test_reserve_with_a_single_slot_still_behaves_like_the_old_single_slot_flow(): void
    {
        $slot = $this->makeSlot(9, 350.00);

        $booking = $this->service->reserve([$slot->getKey()], $this->customer());

        self::assertSame('350.00', (string) $booking->amount);
        self::assertSame($slot->getKey(), $booking->court_slot_id);

        self::assertDatabaseHas('bookings', [
            'id' => $booking->getKey(),
            'court_slot_id' => $slot->getKey(),
            'amount' => '350.00',
        ]);

        $summary = $this->service->summary($booking);
        self::assertSame(1, $summary['slot_count']);
        self::assertCount(1, $summary['slots']);

        $slot->refresh();
        self::assertSame(CourtSlot::STATUS_HELD, $slot->status);
        self::assertSame($booking->getKey(), $slot->held_booking_id);
    }

    /* ===================================================================== */
    /* Helpers                                                                */
    /* ===================================================================== */

    private function makeSlot(int $hour, ?float $price = null): CourtSlot
    {
        return CourtSlot::factory()
            ->forCourt($this->court)
            ->onDate(Carbon::today()->addDay())
            ->atHour($hour)
            ->available()
            ->create($price !== null ? ['price' => $price] : []);
    }

    /**
     * @param  list<int>  $hours
     * @return list<CourtSlot>
     */
    private function makeSlots(array $hours, float $price = 300.00): array
    {
        return array_map(fn (int $hour): CourtSlot => $this->makeSlot($hour, $price), $hours);
    }

    /**
     * @param  list<CourtSlot>  $slots
     * @return list<int>
     */
    private function ids(array $slots): array
    {
        return array_map(static fn (CourtSlot $slot): int => $slot->getKey(), $slots);
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
