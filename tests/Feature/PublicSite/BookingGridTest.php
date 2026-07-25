<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSite;

use App\Models\Court;
use App\Models\CourtSlot;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The public time x court availability grid — real seeded data, real HTTP
 * request, real payload shape.
 */
class BookingGridTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_root_renders_successfully_with_the_expected_grid_shape(): void
    {
        $court = Court::factory()->create(['is_active' => true]);
        $date = Carbon::today()->addDay();

        CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(9)->available()->create();
        CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(10)->available()->create();

        $response = $this->get('/');

        $response->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PublicSite/CourtsIndex')
            ->has('courts')
            ->has('court')
            ->has('days')
            ->has('grid.sections')
            ->has('grid.rates')
            ->where('selectedDate', $date->toDateString())
        );
    }

    public function test_a_reservation_shows_up_as_pending_on_the_next_grid_request(): void
    {
        $court = Court::factory()->create(['is_active' => true]);
        $date = Carbon::today()->addDay();

        // A second, still-available slot the same day keeps this date in the
        // public date navigator once the first one is claimed below — the
        // navigator only lists days with something bookable left on them.
        CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(8)->available()->create();

        $slot = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(14)->available()->create();

        app(BookingService::class)->reserve([$slot->getKey()], [
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
        ]);

        $props = $this->inertiaProps('/?date='.$date->toDateString());

        $cellStatus = $this->findCellStatus($props, $court->getKey(), $slot);

        self::assertSame('pending', $cellStatus, 'A held slot must render as "pending" on the public grid.');
    }

    public function test_a_confirmed_booking_shows_up_as_booked_on_the_grid(): void
    {
        $court = Court::factory()->create(['is_active' => true]);
        $date = Carbon::today()->addDay();

        CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(8)->available()->create();
        $slot = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(15)->booked()->create();

        $props = $this->inertiaProps('/?date='.$date->toDateString());
        $cellStatus = $this->findCellStatus($props, $court->getKey(), $slot);

        self::assertSame('booked', $cellStatus);
    }

    /**
     * @return array<string, mixed>
     */
    private function inertiaProps(string $uri): array
    {
        $response = $this->get($uri);
        $response->assertOk();

        // Package-provided macro: reads the Inertia\Response the controller
        // actually returned, rather than re-parsing rendered HTML/headers.
        return $response->inertiaProps() ?? [];
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function findCellStatus(array $props, int $courtId, CourtSlot $slot): ?string
    {
        $sections = $props['grid']['sections'] ?? [];
        $key = ((string) $slot->start_time).'-'.((string) $slot->end_time);

        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                if ($row['key'] === $key) {
                    return $row['cells'][$courtId]['status'] ?? null;
                }
            }
        }

        return null;
    }
}
