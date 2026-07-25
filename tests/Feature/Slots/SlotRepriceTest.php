<?php

declare(strict_types=1);

namespace Tests\Feature\Slots;

use App\Models\Court;
use App\Models\CourtSlot;
use App\Services\SlotGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SlotGeneratorService::reprice() — the deliberate, explicit escape hatch from
 * the forward-only guarantee proven in SlotPricingTest: an admin can push the
 * CURRENT club-wide rates onto a court's already-generated schedule on demand,
 * but only ever onto `available` rows, and never before today. Pricing is two
 * tiers now (non-peak/peak) and the peak window may cross midnight.
 */
class SlotRepriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('booking.pricing.non_peak_rate', 450);
        config()->set('booking.pricing.peak_rate', 500);
        config()->set('booking.pricing.peak_start', '16:00');
        config()->set('booking.pricing.peak_end', '02:00');
    }

    private function generator(): SlotGeneratorService
    {
        return app(SlotGeneratorService::class);
    }

    public function test_reprice_only_touches_available_slots(): void
    {
        $court = Court::factory()->create();
        $date = Carbon::today()->addDay();

        // Stale prices, as if generated before the current rates were ever set.
        // 9am is non-peak (₱450); the others sit in the peak window (₱500).
        $available = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(9)->available()
            ->create(['price' => 111.00]);
        $held = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(18)->held()
            ->create(['price' => 111.00]);
        $booked = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(19)->booked()
            ->create(['price' => 111.00]);
        $blocked = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(20)->blocked()
            ->create(['price' => 111.00]);

        $this->generator()->reprice($court);

        self::assertSame(450.00, (float) $available->refresh()->price, 'The available slot must be repriced.');
        self::assertSame(111.00, (float) $held->refresh()->price, 'A held slot must never be touched.');
        self::assertSame(111.00, (float) $booked->refresh()->price, 'A booked slot must never be touched.');
        self::assertSame(111.00, (float) $blocked->refresh()->price, 'A blocked slot must never be touched.');
    }

    public function test_reprice_buckets_by_tier_across_the_midnight_wrap(): void
    {
        $court = Court::factory()->create();
        $date = Carbon::today()->addDay();

        // Non-peak: 09:00, 15:00. Peak: 16:00, 23:00, and 01:00 (past midnight).
        $nine = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(9)->available()->create(['price' => 1.00]);
        $threePm = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(15)->available()->create(['price' => 1.00]);
        $fourPm = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(16)->available()->create(['price' => 1.00]);
        $elevenPm = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(23)->available()->create(['price' => 1.00]);
        $oneAm = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(1)->available()->create(['price' => 1.00]);

        $summary = $this->generator()->reprice($court);

        self::assertSame(450.00, (float) $nine->refresh()->price, '9am is non-peak.');
        self::assertSame(450.00, (float) $threePm->refresh()->price, '3pm is still non-peak.');
        self::assertSame(500.00, (float) $fourPm->refresh()->price, '4pm flips to peak.');
        self::assertSame(500.00, (float) $elevenPm->refresh()->price, '11pm is peak.');
        self::assertSame(500.00, (float) $oneAm->refresh()->price, '1am is inside the wrapped peak window.');

        self::assertSame(5, $summary['total']);

        $byTier = collect($summary['breakdown'])->pluck('count', 'tier');
        self::assertSame(2, $byTier['non_peak'], '9am and 3pm are non-peak.');
        self::assertSame(3, $byTier['peak'], '4pm, 11pm and 1am are peak.');
    }

    public function test_reprice_respects_an_explicit_date_range(): void
    {
        $court = Court::factory()->create();

        $inRange = Carbon::today()->addDays(3);
        $beforeRange = Carbon::today()->addDay();
        $afterRange = Carbon::today()->addDays(10);

        $slotInRange = CourtSlot::factory()->forCourt($court)->onDate($inRange)->atHour(9)->available()->create(['price' => 1.00]);
        $slotBefore = CourtSlot::factory()->forCourt($court)->onDate($beforeRange)->atHour(9)->available()->create(['price' => 1.00]);
        $slotAfter = CourtSlot::factory()->forCourt($court)->onDate($afterRange)->atHour(9)->available()->create(['price' => 1.00]);

        $this->generator()->reprice($court, $inRange->toDateString(), $inRange->toDateString());

        self::assertSame(450.00, (float) $slotInRange->refresh()->price, 'The slot inside the window must be repriced.');
        self::assertSame(1.00, (float) $slotBefore->refresh()->price, 'A slot before the window must be untouched.');
        self::assertSame(1.00, (float) $slotAfter->refresh()->price, 'A slot after the window must be untouched.');
    }

    public function test_reprice_never_reaches_before_today_even_when_asked(): void
    {
        $court = Court::factory()->create();

        $yesterday = Carbon::yesterday();
        $today = Carbon::today();

        $pastSlot = CourtSlot::factory()->forCourt($court)->onDate($yesterday)->atHour(9)->available()->create(['price' => 1.00]);
        $todaySlot = CourtSlot::factory()->forCourt($court)->onDate($today)->atHour(9)->available()->create(['price' => 1.00]);

        // Deliberately ask for a range starting yesterday — the service must
        // clamp it to today rather than obey.
        $this->generator()->reprice($court, $yesterday->toDateString(), null);

        self::assertSame(1.00, (float) $pastSlot->refresh()->price, 'A past slot must never be repriced, even on request.');
        self::assertSame(450.00, (float) $todaySlot->refresh()->price, "Today's slot is the real floor and must be repriced.");
    }

    public function test_a_slot_already_at_the_current_rate_is_not_counted_as_changed(): void
    {
        $court = Court::factory()->create();
        $date = Carbon::today()->addDay();

        CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(9)->available()->create(['price' => 450.00]);

        $summary = $this->generator()->reprice($court);

        self::assertSame(0, $summary['total'], 'A slot already at the current rate must not be reported as changed.');
    }

    public function test_reprice_does_not_touch_a_different_courts_slots(): void
    {
        $repriced = Court::factory()->create();
        $untouched = Court::factory()->create();

        $date = Carbon::today()->addDay();

        $ownSlot = CourtSlot::factory()->forCourt($repriced)->onDate($date)->atHour(9)->available()->create(['price' => 1.00]);
        $otherSlot = CourtSlot::factory()->forCourt($untouched)->onDate($date)->atHour(9)->available()->create(['price' => 250.00]);

        $this->generator()->reprice($repriced);

        self::assertSame(450.00, (float) $ownSlot->refresh()->price);
        self::assertSame(250.00, (float) $otherSlot->refresh()->price, "A different court's slots must be completely unaffected.");
    }

    public function test_reprice_all_reprices_every_court_in_one_pass(): void
    {
        $courtA = Court::factory()->create();
        $courtB = Court::factory()->create();

        $date = Carbon::today()->addDay();

        // Both courts hold stale prices — the global equivalent of reprice()
        // must reach every one of them, not just a selected court.
        $a = CourtSlot::factory()->forCourt($courtA)->onDate($date)->atHour(9)->available()->create(['price' => 1.00]);
        $b = CourtSlot::factory()->forCourt($courtB)->onDate($date)->atHour(18)->available()->create(['price' => 1.00]);

        $summary = $this->generator()->repriceAll();

        self::assertSame(450.00, (float) $a->refresh()->price, 'Court A non-peak slot repriced.');
        self::assertSame(500.00, (float) $b->refresh()->price, 'Court B peak slot repriced.');

        self::assertSame(2, $summary['courts']);
        self::assertSame(2, $summary['courts_changed']);
        self::assertSame(2, $summary['total']);
    }

    public function test_reprice_all_leaves_held_and_booked_slots_across_courts_alone(): void
    {
        $courtA = Court::factory()->create();
        $courtB = Court::factory()->create();

        $date = Carbon::today()->addDay();

        $available = CourtSlot::factory()->forCourt($courtA)->onDate($date)->atHour(9)->available()->create(['price' => 1.00]);
        $held = CourtSlot::factory()->forCourt($courtA)->onDate($date)->atHour(18)->held()->create(['price' => 1.00]);
        $booked = CourtSlot::factory()->forCourt($courtB)->onDate($date)->atHour(19)->booked()->create(['price' => 1.00]);

        $this->generator()->repriceAll();

        self::assertSame(450.00, (float) $available->refresh()->price, 'The available slot is repriced.');
        self::assertSame(1.00, (float) $held->refresh()->price, 'A held slot is never touched, on any court.');
        self::assertSame(1.00, (float) $booked->refresh()->price, 'A booked slot is never touched, on any court.');
    }
}
