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
 * Court pricing: a rate grid of court category × tier (non-peak/peak), the
 * generator resolves a slot's price from its own start time AND its court's
 * category, the peak window may cross midnight — and, the client's hard rule —
 * changing the rate table never reprices a slot that already exists.
 */
class SlotPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Distinct, predictable rates on a window that crosses midnight: peak
        // runs 4pm–2am. The two categories are deliberately far apart so a test
        // reading the wrong row cannot coincidentally pass.
        config()->set('booking.pricing.categories.normal.non_peak_rate', 450);
        config()->set('booking.pricing.categories.normal.peak_rate', 500);
        config()->set('booking.pricing.categories.skinny.non_peak_rate', 150);
        config()->set('booking.pricing.categories.skinny.peak_rate', 250);
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
        self::assertSame(450.0, $pricing->rateFor('09:00', Court::CATEGORY_NORMAL));

        // Peak begins exactly at 16:00.
        self::assertSame('peak', $pricing->tierFor('16:00'));
        self::assertSame('peak', $pricing->tierFor('23:00'));
        self::assertSame(500.0, $pricing->rateFor('18:00', Court::CATEGORY_NORMAL));
    }

    public function test_the_two_categories_charge_their_own_rates_for_the_same_hour(): void
    {
        $pricing = $this->pricing();

        // The tier is a property of the clock and is shared...
        self::assertSame('non_peak', $pricing->tierFor('09:00'));
        self::assertSame('peak', $pricing->tierFor('18:00'));

        // ...but the money is a property of the court's category.
        self::assertSame(450.0, $pricing->rateFor('09:00', Court::CATEGORY_NORMAL));
        self::assertSame(150.0, $pricing->rateFor('09:00', Court::CATEGORY_SKINNY));
        self::assertSame(500.0, $pricing->rateFor('18:00', Court::CATEGORY_NORMAL));
        self::assertSame(250.0, $pricing->rateFor('18:00', Court::CATEGORY_SKINNY));

        // A court advertises its OWN cheapest hour, never the club's — a
        // full-size court must not show the Skinny Court's price.
        self::assertSame(450.0, $pricing->fromRateFor(Court::CATEGORY_NORMAL));
        self::assertSame(150.0, $pricing->fromRateFor(Court::CATEGORY_SKINNY));
        self::assertSame(150.0, $pricing->fromRate(), 'The club-wide "from" is the cheapest hour anywhere.');

        // An unrecognised category prices as a normal court rather than
        // throwing — the safe direction, since it is the dearer row.
        self::assertSame(500.0, $pricing->rateFor('18:00', 'not-a-category'));
    }

    public function test_the_generator_prices_each_court_from_its_own_category(): void
    {
        $normal = Court::factory()->create();
        $skinny = Court::factory()->skinny()->create();
        $date = Carbon::today()->addDays(9);

        foreach ([$normal, $skinny] as $court) {
            $this->generator()->generate($court, [
                'start_date' => $date->toDateString(),
                'end_date' => $date->toDateString(),
                'days_of_week' => [0, 1, 2, 3, 4, 5, 6],
                'opening_time' => '08:00',
                'closing_time' => '20:00',
                'duration_minutes' => 60,
                'price' => null,
            ]);
        }

        $priceAt = fn (Court $court, string $time): float => (float) CourtSlot::query()
            ->where('court_id', $court->getKey())
            ->where('slot_date', $date->toDateString())
            ->where('start_time', $time)
            ->value('price');

        // Same date, same hour, same run — two different prices, because the
        // courts sit on different rows of the rate table.
        self::assertSame(450.00, $priceAt($normal, '09:00:00'));
        self::assertSame(150.00, $priceAt($skinny, '09:00:00'));
        self::assertSame(500.00, $priceAt($normal, '18:00:00'));
        self::assertSame(250.00, $priceAt($skinny, '18:00:00'));
    }

    public function test_the_peak_window_wraps_past_midnight(): void
    {
        $pricing = $this->pricing();

        // Peak 16:00–02:00 covers the small hours of the next day.
        self::assertSame('peak', $pricing->tierFor('00:00'));
        self::assertSame('peak', $pricing->tierFor('01:59'));
        self::assertSame(500.0, $pricing->rateFor('01:00', Court::CATEGORY_NORMAL));

        // 02:00 is the exclusive end — back to non-peak.
        self::assertSame('non_peak', $pricing->tierFor('02:00'));
        self::assertSame(450.0, $pricing->rateFor('02:00', Court::CATEGORY_NORMAL));

        // Full "HH:MM:SS" strings and datetime objects resolve the same way.
        self::assertSame(500.0, $pricing->rateFor('17:00:00', Court::CATEGORY_NORMAL));
        self::assertSame(500.0, $pricing->rateFor(Carbon::parse('2026-07-21 20:15:00'), Court::CATEGORY_NORMAL));

        // Odd input never throws; it prices at that category's non-peak rate.
        self::assertSame(450.0, $pricing->rateFor('not-a-time', Court::CATEGORY_NORMAL));
        self::assertSame(150.0, $pricing->rateFor('not-a-time', Court::CATEGORY_SKINNY));
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

        self::assertSame('category', $preview['pricing']['source']);
        self::assertSame(Court::CATEGORY_NORMAL, $preview['pricing']['category']);

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
        config()->set('booking.pricing.categories.normal.non_peak_rate', 999);
        config()->set('booking.pricing.categories.normal.peak_rate', 888);

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
