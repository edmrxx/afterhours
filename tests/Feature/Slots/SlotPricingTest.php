<?php

declare(strict_types=1);

namespace Tests\Feature\Slots;

use App\Models\Court;
use App\Models\CourtSlot;
use App\Services\PricingService;
use App\Services\SlotGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Global court pricing: two tiers (non-peak/peak) set club-wide, the generator
 * resolves them per slot from the slot's own start time, the peak window may
 * cross midnight — and, the client's hard rule — changing the rate table never
 * reprices a slot that already exists.
 */
class SlotPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Distinct, predictable rates on the client's default window: peak runs
        // 4pm–2am (crossing midnight) at ₱500, everything else is non-peak ₱450.
        config()->set('booking.pricing.non_peak_rate', 450);
        config()->set('booking.pricing.peak_rate', 500);
        config()->set('booking.pricing.peak_start', '16:00');
        config()->set('booking.pricing.peak_end', '02:00');
    }

    private function pricing(): PricingService
    {
        return app(PricingService::class);
    }

    private function generator(): SlotGeneratorService
    {
        return app(SlotGeneratorService::class);
    }

    /* ------------------------------------------------------------------ */
    /* PricingService::tierFor() / rateFor()                              */
    /* ------------------------------------------------------------------ */

    public function test_the_tier_and_rate_resolve_at_the_window_boundaries(): void
    {
        $pricing = $this->pricing();

        // Non-peak runs 07:00 up to (not including) 16:00.
        self::assertSame('non_peak', $pricing->tierFor('07:00'));
        self::assertSame('non_peak', $pricing->tierFor('15:59'));
        self::assertSame(450.0, $pricing->rateFor('09:00'));

        // Peak begins exactly at 16:00.
        self::assertSame('peak', $pricing->tierFor('16:00'));
        self::assertSame('peak', $pricing->tierFor('23:00'));
        self::assertSame(500.0, $pricing->rateFor('18:00'));
    }

    public function test_the_peak_window_wraps_past_midnight(): void
    {
        $pricing = $this->pricing();

        // Peak 16:00–02:00 covers the small hours of the next day.
        self::assertSame('peak', $pricing->tierFor('00:00'));
        self::assertSame('peak', $pricing->tierFor('01:59'));
        self::assertSame(500.0, $pricing->rateFor('01:00'));

        // 02:00 is the exclusive end — back to non-peak.
        self::assertSame('non_peak', $pricing->tierFor('02:00'));
        self::assertSame(450.0, $pricing->rateFor('02:00'));

        // Full "HH:MM:SS" strings and datetime objects resolve the same way.
        self::assertSame(500.0, $pricing->rateFor('17:00:00'));
        self::assertSame(500.0, $pricing->rateFor(Carbon::parse('2026-07-21 20:15:00')));

        // Odd input never throws; it prices at the non-peak rate.
        self::assertSame(450.0, $pricing->rateFor('not-a-time'));
    }

    /* ------------------------------------------------------------------ */
    /* Generator                                                          */
    /* ------------------------------------------------------------------ */

    public function test_a_single_run_prices_non_peak_and_peak_rows_including_past_midnight(): void
    {
        $court = Court::factory()->create();
        $date = Carbon::today()->addDays(5);

        // 07:00 → 02:00 next day, hourly → spans non-peak, peak, and the
        // post-midnight tail of the peak window in one pass.
        $this->generator()->generate($court, [
            'start_date' => $date->toDateString(),
            'end_date' => $date->toDateString(),
            'days_of_week' => [0, 1, 2, 3, 4, 5, 6],
            'opening_time' => '07:00',
            'closing_time' => '02:00',
            'duration_minutes' => 60,
            // No price → the global rate table decides.
            'price' => null,
        ]);

        $priceAt = fn (string $time): float => (float) CourtSlot::query()
            ->where('court_id', $court->getKey())
            ->where('slot_date', $date->toDateString())
            ->where('start_time', $time)
            ->value('price');

        self::assertSame(450.00, $priceAt('09:00:00'), 'A 9am slot must carry the non-peak rate.');
        self::assertSame(450.00, $priceAt('15:00:00'), '3pm is still non-peak.');
        self::assertSame(500.00, $priceAt('16:00:00'), '4pm flips to peak.');
        self::assertSame(500.00, $priceAt('23:00:00'), '11pm is peak.');
        self::assertSame(500.00, $priceAt('01:00:00'), '1am is still inside the peak window.');

        // Exactly two distinct prices made it into the table.
        $distinct = CourtSlot::query()
            ->where('court_id', $court->getKey())
            ->distinct()
            ->pluck('price');

        self::assertCount(2, $distinct);
    }

    public function test_an_explicit_price_override_applies_flatly_to_every_row(): void
    {
        $court = Court::factory()->create();
        $date = Carbon::today()->addDays(6);

        $this->generator()->generate($court, [
            'start_date' => $date->toDateString(),
            'end_date' => $date->toDateString(),
            'days_of_week' => [0, 1, 2, 3, 4, 5, 6],
            'opening_time' => '08:00',
            'closing_time' => '20:00',
            'duration_minutes' => 60,
            // A one-off promo run: this wins for every slot, tier rates ignored.
            'price' => 199.00,
        ]);

        $prices = CourtSlot::query()
            ->where('court_id', $court->getKey())
            ->distinct()
            ->pluck('price');

        self::assertCount(1, $prices);
        self::assertSame(199.00, (float) $prices->first());
    }

    public function test_preview_reports_the_per_tier_breakdown_it_will_write(): void
    {
        $court = Court::factory()->create();
        $date = Carbon::today()->addDays(7);

        $preview = $this->generator()->preview($court, [
            'start_date' => $date->toDateString(),
            'end_date' => $date->toDateString(),
            'days_of_week' => [0, 1, 2, 3, 4, 5, 6],
            'opening_time' => '08:00',
            'closing_time' => '20:00',
            'duration_minutes' => 60,
            'price' => null,
        ]);

        self::assertSame('global', $preview['pricing']['source']);

        $rates = collect($preview['pricing']['breakdown'])->pluck('rate', 'tier');

        self::assertSame(450.00, $rates['non_peak']);
        self::assertSame(500.00, $rates['peak']);
    }

    /* ------------------------------------------------------------------ */
    /* Forward-only guarantee                                             */
    /* ------------------------------------------------------------------ */

    public function test_editing_the_rate_table_does_not_reprice_existing_slots(): void
    {
        $court = Court::factory()->create();
        $date = Carbon::today()->addDays(8);

        $this->generator()->generate($court, [
            'start_date' => $date->toDateString(),
            'end_date' => $date->toDateString(),
            'days_of_week' => [0, 1, 2, 3, 4, 5, 6],
            'opening_time' => '08:00',
            'closing_time' => '20:00',
            'duration_minutes' => 60,
            'price' => null,
        ]);

        // Snapshot every slot's price before the rate change.
        $before = CourtSlot::query()
            ->where('court_id', $court->getKey())
            ->orderBy('start_time')
            ->pluck('price', 'start_time');

        // Change the whole rate table, dramatically.
        config()->set('booking.pricing.non_peak_rate', 999);
        config()->set('booking.pricing.peak_rate', 888);

        $after = CourtSlot::query()
            ->where('court_id', $court->getKey())
            ->orderBy('start_time')
            ->pluck('price', 'start_time');

        self::assertEquals(
            $before->toArray(),
            $after->toArray(),
            'Changing the rate table must never reprice slots that already exist.',
        );

        // And none of the new numbers leaked into any row.
        $prices = CourtSlot::query()->where('court_id', $court->getKey())->pluck('price');

        self::assertFalse($prices->contains(fn ($p): bool => in_array((float) $p, [999.00, 888.00], true)));
    }
}
