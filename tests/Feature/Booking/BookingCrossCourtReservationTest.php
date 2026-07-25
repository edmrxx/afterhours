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
 * Cross-court booking: a guest picks slots on DIFFERENT courts in one request —
 * Court A at 9 AND Court B at 2 — and gets ONE combined booking, priced, held,
 * confirmed, released and described together. This used to throw a mixed-court
 * guard; that guard is gone.
 *
 * No schema changed: `bookings.court_id` / `court_slot_id` stay singular and now
 * mean "the primary (earliest) slot's court" — a backward-compatible
 * representative. The plural truth lives in `booking_slots` / `slots()`, and the
 * read model (summary()) is what any multi-court display must read. The property
 * threaded through this whole file: a booking that happens to span ONE court must
 * behave byte-identically to before, and a booking that spans two must never lose
 * either court anywhere — pricing, holds, release, or description.
 */
class BookingCrossCourtReservationTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    private Court $courtA;

    private Court $courtB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BookingService::class);
        $this->courtA = Court::factory()->create();
        $this->courtB = Court::factory()->create();
    }

    /* ===================================================================== */
    /* 1. reserve() across two courts — ONE combined booking, summed price   */
    /* ===================================================================== */

    public function test_reserve_across_two_courts_creates_one_booking_summing_both_slot_prices(): void
    {
        // Two distinct courts, two distinct prices whose sum matches neither
        // individual price nor a doubled primary — so an implementation that
        // charged only the primary slot's court, or forgot the second slot
        // entirely, cannot pass this by accident.
        $slotA = $this->makeSlot($this->courtA, 9, 300.00);
        $slotB = $this->makeSlot($this->courtB, 14, 475.00);

        $booking = $this->service->reserve([$slotA->getKey(), $slotB->getKey()], $this->customer());

        // Exactly one booking — a cross-court request must NOT split into two.
        self::assertSame(1, Booking::query()->count(), 'A cross-court request must produce exactly one booking.');

        // Amount is the exact sum of BOTH slots' prices, fixed at reserve time.
        self::assertSame('775.00', (string) $booking->amount);

        self::assertDatabaseHas('bookings', [
            'id' => $booking->getKey(),
            'amount' => '775.00',
        ]);

        // The primary FK columns resolve to the earliest (chronological) slot's
        // court — Court A at 9 AM — a backward-compatible representative only.
        self::assertSame($slotA->getKey(), $booking->court_slot_id);
        self::assertSame($this->courtA->getKey(), $booking->court_id);
    }

    /* ===================================================================== */
    /* 2. Every slot on BOTH courts is held, and booking_slots links them all  */
    /* ===================================================================== */

    public function test_reserve_holds_every_slot_on_both_courts_and_links_them_all_in_booking_slots(): void
    {
        $slotA = $this->makeSlot($this->courtA, 9, 300.00);
        $slotB = $this->makeSlot($this->courtB, 14, 475.00);

        $booking = $this->service->reserve([$slotA->getKey(), $slotB->getKey()], $this->customer());

        // Both slots are HELD for this one booking, regardless of court.
        foreach ([$slotA, $slotB] as $slot) {
            $slot->refresh();
            self::assertSame(CourtSlot::STATUS_HELD, $slot->status);
            self::assertSame($booking->getKey(), $slot->held_booking_id);
        }

        // The durable pivot links EVERY slot on BOTH courts to the booking —
        // this is the authoritative cross-court set, independent of the live
        // held_booking_id pointer.
        foreach ([$slotA, $slotB] as $slot) {
            self::assertDatabaseHas('booking_slots', [
                'booking_id' => $booking->getKey(),
                'court_slot_id' => $slot->getKey(),
            ]);
        }

        self::assertSame(
            2,
            $booking->fresh()->slots()->count(),
            'booking_slots must link exactly the two slots the booking spans.',
        );
    }

    /* ===================================================================== */
    /* 3. confirm() — every slot on both courts becomes permanently booked   */
    /* ===================================================================== */

    public function test_confirm_books_every_slot_across_both_courts(): void
    {
        $admin = User::factory()->create();
        $slotA = $this->makeSlot($this->courtA, 9, 300.00);
        $slotB = $this->makeSlot($this->courtB, 14, 475.00);

        $booking = $this->service->reserve([$slotA->getKey(), $slotB->getKey()], $this->customer());
        $this->service->submitPayment($booking, '1234567890123');

        $confirmed = $this->service->confirm($booking->fresh(), $admin);

        self::assertSame(Booking::STATUS_CONFIRMED, $confirmed->status);

        foreach ([$slotA, $slotB] as $slot) {
            $slot->refresh();
            self::assertSame(CourtSlot::STATUS_BOOKED, $slot->status);
            self::assertSame($confirmed->getKey(), $slot->held_booking_id);
        }
    }

    /* ===================================================================== */
    /* 4. cancel() / reject() / expire() — release ALL slots on BOTH courts  */
    /* ===================================================================== */

    public function test_cancel_releases_every_slot_across_both_courts(): void
    {
        $slotA = $this->makeSlot($this->courtA, 9, 300.00);
        $slotB = $this->makeSlot($this->courtB, 14, 475.00);

        $booking = $this->service->reserve([$slotA->getKey(), $slotB->getKey()], $this->customer());

        $cancelled = $this->service->cancel($booking);

        self::assertSame(Booking::STATUS_CANCELLED, $cancelled->status);

        foreach ([$slotA, $slotB] as $slot) {
            $slot->refresh();
            self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
            self::assertNull($slot->held_booking_id);
        }
    }

    public function test_reject_releases_every_slot_across_both_courts(): void
    {
        $admin = User::factory()->create();
        $slotA = $this->makeSlot($this->courtA, 9, 300.00);
        $slotB = $this->makeSlot($this->courtB, 14, 475.00);

        $booking = $this->service->reserve([$slotA->getKey(), $slotB->getKey()], $this->customer());
        $this->service->submitPayment($booking, '1234567890123');

        $rejected = $this->service->reject($booking->fresh(), $admin, 'No match.');

        self::assertSame(Booking::STATUS_REJECTED, $rejected->status);

        foreach ([$slotA, $slotB] as $slot) {
            $slot->refresh();
            self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
            self::assertNull($slot->held_booking_id);
        }
    }

    public function test_expire_releases_every_slot_across_both_courts(): void
    {
        $slotA = $this->makeSlot($this->courtA, 9, 300.00);
        $slotB = $this->makeSlot($this->courtB, 14, 475.00);

        $booking = $this->service->reserve([$slotA->getKey(), $slotB->getKey()], $this->customer());

        // Force the hold clock into the past so expire() finds it lapsed.
        $booking->forceFill(['hold_expires_at' => Carbon::now()->subMinute()])->save();

        $released = $this->service->expire($booking->fresh());

        self::assertTrue($released);

        $booking->refresh();
        self::assertSame(Booking::STATUS_EXPIRED, $booking->status);

        foreach ([$slotA, $slotB] as $slot) {
            $slot->refresh();
            self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status);
            self::assertNull($slot->held_booking_id);
        }
    }

    /* ===================================================================== */
    /* 5. summary() — the multi-court read model                             */
    /* ===================================================================== */

    public function test_summary_of_a_cross_court_booking_reports_both_courts_per_slot_and_at_the_top(): void
    {
        // Court A at 9 (earlier) and Court B at 14 (later): chronological order
        // is A then B, so both court_names and the per-slot court list must
        // read A first, B second.
        $slotA = $this->makeSlot($this->courtA, 9, 300.00);
        $slotB = $this->makeSlot($this->courtB, 14, 475.00);

        $booking = $this->service->reserve([$slotA->getKey(), $slotB->getKey()], $this->customer());

        $summary = $this->service->summary($booking);

        // Top-level: it knows it spans more than one court, and names both
        // distinct courts in chronological first-appearance order.
        self::assertTrue($summary['is_multi_court']);
        self::assertSame([$this->courtA->name, $this->courtB->name], $summary['court_names']);

        // The primary (singular) court_name stays the earliest slot's court —
        // unchanged meaning, backward-compatible with every single-court read.
        self::assertSame($this->courtA->name, $summary['court_name']);

        // Every slot entry carries its OWN court_id and court_name.
        self::assertSame(2, $summary['slot_count']);
        self::assertCount(2, $summary['slots']);
        self::assertSame(
            [$this->courtA->name, $this->courtB->name],
            array_column($summary['slots'], 'court_name'),
        );
        self::assertSame(
            [$this->courtA->getKey(), $this->courtB->getKey()],
            array_column($summary['slots'], 'court_id'),
        );
    }

    public function test_summary_dedupes_court_names_when_one_court_contributes_several_slots(): void
    {
        // Court A twice (9 and 10), Court B once (14): three slots, but only
        // TWO distinct courts. court_names must dedupe to two while slots[]
        // still lists all three with their own courts.
        $slotA1 = $this->makeSlot($this->courtA, 9, 300.00);
        $slotA2 = $this->makeSlot($this->courtA, 10, 300.00);
        $slotB = $this->makeSlot($this->courtB, 14, 475.00);

        $booking = $this->service->reserve(
            [$slotA1->getKey(), $slotA2->getKey(), $slotB->getKey()],
            $this->customer(),
        );

        $summary = $this->service->summary($booking);

        self::assertTrue($summary['is_multi_court']);
        self::assertSame([$this->courtA->name, $this->courtB->name], $summary['court_names']);

        self::assertSame(3, $summary['slot_count']);
        self::assertSame(
            [$this->courtA->name, $this->courtA->name, $this->courtB->name],
            array_column($summary['slots'], 'court_name'),
        );
    }

    /* ===================================================================== */
    /* 6. Regression guard — a single-court booking is unchanged             */
    /* ===================================================================== */

    public function test_single_court_booking_reports_not_multi_court_and_is_unchanged(): void
    {
        // Two slots, but both on the SAME court: this is NOT cross-court, and
        // must render exactly as it did before the feature existed.
        $slot1 = $this->makeSlot($this->courtA, 9, 300.00);
        $slot2 = $this->makeSlot($this->courtA, 10, 350.00);

        $booking = $this->service->reserve([$slot1->getKey(), $slot2->getKey()], $this->customer());

        $summary = $this->service->summary($booking);

        // Not multi-court, and court_names collapses to the single court —
        // in lock-step with the primary court_name.
        self::assertFalse($summary['is_multi_court']);
        self::assertSame([$this->courtA->name], $summary['court_names']);
        self::assertSame($this->courtA->name, $summary['court_name']);

        // Every per-slot entry names that one court and nothing else.
        self::assertSame(
            [$this->courtA->name, $this->courtA->name],
            array_column($summary['slots'], 'court_name'),
        );
        self::assertSame(
            [$this->courtA->getKey(), $this->courtA->getKey()],
            array_column($summary['slots'], 'court_id'),
        );

        // The primary FK columns still point at the earliest slot on the one
        // court, and the amount is the plain sum — nothing about the singular
        // path changed.
        self::assertSame($slot1->getKey(), $booking->court_slot_id);
        self::assertSame($this->courtA->getKey(), $booking->court_id);
        self::assertSame('650.00', (string) $booking->amount);
    }

    /* ===================================================================== */
    /* Helpers                                                                */
    /* ===================================================================== */

    private function makeSlot(Court $court, int $hour, ?float $price = null): CourtSlot
    {
        return CourtSlot::factory()
            ->forCourt($court)
            ->onDate(Carbon::today()->addDay())
            ->atHour($hour)
            ->available()
            ->create($price !== null ? ['price' => $price] : []);
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
