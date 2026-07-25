<?php

declare(strict_types=1);

namespace Tests\Feature\Slots;

use App\Models\Court;
use App\Models\CourtSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `php artisan slots:reprice-all` — the console one-shot that pushes the current
 * club-wide rates onto every court's available future schedule, for when the
 * rates in Settings are already right but the existing slots are stale.
 */
class RepriceAllCommandTest extends TestCase
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

    public function test_it_reprices_every_courts_available_future_slots(): void
    {
        $courtA = Court::factory()->create();
        $courtB = Court::factory()->create();

        $date = Carbon::today()->addDay();

        $nonPeak = CourtSlot::factory()->forCourt($courtA)->onDate($date)->atHour(9)->available()->create(['price' => 111.00]);
        $peak = CourtSlot::factory()->forCourt($courtB)->onDate($date)->atHour(18)->available()->create(['price' => 111.00]);

        // Must be left alone: a held slot, and one in the past.
        $held = CourtSlot::factory()->forCourt($courtA)->onDate($date)->atHour(20)->held()->create(['price' => 111.00]);
        $past = CourtSlot::factory()->forCourt($courtB)->onDate(Carbon::yesterday())->atHour(9)->available()->create(['price' => 111.00]);

        $this->artisan('slots:reprice-all')->assertSuccessful();

        self::assertSame(450.00, (float) $nonPeak->refresh()->price, 'Non-peak slot repriced.');
        self::assertSame(500.00, (float) $peak->refresh()->price, 'Peak slot repriced.');
        self::assertSame(111.00, (float) $held->refresh()->price, 'Held slot untouched.');
        self::assertSame(111.00, (float) $past->refresh()->price, 'Past slot untouched.');
    }

    public function test_it_reports_when_nothing_needs_repricing(): void
    {
        $court = Court::factory()->create();
        CourtSlot::factory()->forCourt($court)->onDate(Carbon::today()->addDay())->atHour(9)->available()->create(['price' => 450.00]);

        $this->artisan('slots:reprice-all')
            ->expectsOutputToContain('nothing changed')
            ->assertSuccessful();
    }

    public function test_it_is_a_no_op_with_no_courts(): void
    {
        $this->artisan('slots:reprice-all')
            ->expectsOutputToContain('No courts found')
            ->assertSuccessful();
    }
}
