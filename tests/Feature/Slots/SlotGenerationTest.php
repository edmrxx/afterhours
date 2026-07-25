<?php

declare(strict_types=1);

namespace Tests\Feature\Slots;

use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\User;
use App\Services\SlotGeneratorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SlotGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generator_produces_the_expected_count_for_a_known_range_and_duration(): void
    {
        $court = Court::factory()->create();

        $start = Carbon::today()->addDays(10);
        $end = $start->copy()->addDays(6);

        // A full week, every day, 08:00 to 12:00 in 60-minute blocks:
        // 7 days * 4 slots/day = 28.
        $summary = app(SlotGeneratorService::class)->generate($court, [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days_of_week' => [0, 1, 2, 3, 4, 5, 6],
            'opening_time' => '08:00',
            'closing_time' => '12:00',
            'duration_minutes' => 60,
            'price' => 400,
        ]);

        self::assertSame(28, $summary['created']);
        self::assertSame(28, $summary['total']);
        self::assertSame(0, $summary['skipped']);
        self::assertSame(28, CourtSlot::query()->where('court_id', $court->getKey())->count());
    }

    public function test_generator_is_safe_to_rerun_without_duplicating(): void
    {
        $court = Court::factory()->create();

        $start = Carbon::today()->addDays(20);
        $end = $start->copy()->addDays(6);

        $params = [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days_of_week' => [1, 2, 3, 4, 5],
            'opening_time' => '09:00',
            'closing_time' => '17:00',
            'duration_minutes' => 60,
            'price' => 350,
        ];

        $first = app(SlotGeneratorService::class)->generate($court, $params);
        self::assertGreaterThan(0, $first['created']);

        $countAfterFirst = CourtSlot::query()->where('court_id', $court->getKey())->count();

        $second = app(SlotGeneratorService::class)->generate($court, $params);

        self::assertSame(0, $second['created'], 'Re-running the same pattern must not create new rows.');
        self::assertSame($first['total'], $second['skipped']);

        $countAfterSecond = CourtSlot::query()->where('court_id', $court->getKey())->count();
        self::assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_generator_refuses_to_exceed_the_configured_maximum(): void
    {
        config(['booking.max_generate_slots' => 10]);

        $court = Court::factory()->create();

        $this->expectException(ValidationException::class);

        $start = Carbon::today()->addDays(40);
        $end = $start->copy()->addDays(29);

        // 30 days * 48 slots/day (30-minute blocks across a full 24h day) = 1440,
        // comfortably over the ceiling of 10.
        app(SlotGeneratorService::class)->generate($court, [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days_of_week' => [0, 1, 2, 3, 4, 5, 6],
            'opening_time' => '00:00',
            'closing_time' => '00:00',
            'duration_minutes' => 30,
            'price' => 300,
        ]);
    }

    public function test_generator_preview_reports_over_limit_without_writing_anything(): void
    {
        config(['booking.max_generate_slots' => 10]);

        $court = Court::factory()->create();

        $start = Carbon::today()->addDays(40);
        $end = $start->copy()->addDays(29);

        $preview = app(SlotGeneratorService::class)->preview($court, [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days_of_week' => [0, 1, 2, 3, 4, 5, 6],
            'opening_time' => '00:00',
            'closing_time' => '00:00',
            'duration_minutes' => 30,
            'price' => 300,
        ]);

        self::assertTrue($preview['exceeds_limit']);
        self::assertSame(0, CourtSlot::query()->where('court_id', $court->getKey())->count());
    }

    public function test_manual_slot_creation_rejects_a_genuinely_overlapping_time_range(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $court = Court::factory()->create();
        $date = Carbon::today()->addDays(3)->toDateString();

        CourtSlot::factory()->forCourt($court)->onDate($date)->create([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        // 09:30–10:30 overlaps the 09:00–10:00 slot above.
        $response = $this->actingAs($admin)->post('/admin/slots', [
            'court_id' => $court->getKey(),
            'slot_date' => $date,
            'start_time' => '09:30',
            'end_time' => '10:30',
            'price' => 400,
        ]);

        $response->assertSessionHasErrors('start_time');
        self::assertSame(1, CourtSlot::query()->where('court_id', $court->getKey())->count());
    }

    public function test_manual_slot_creation_accepts_a_back_to_back_non_overlapping_range(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $court = Court::factory()->create();
        $date = Carbon::today()->addDays(3)->toDateString();

        CourtSlot::factory()->forCourt($court)->onDate($date)->create([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        // 10:00–11:00 starts exactly when the first ends — not an overlap.
        $response = $this->actingAs($admin)->post('/admin/slots', [
            'court_id' => $court->getKey(),
            'slot_date' => $date,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'price' => 400,
        ]);

        $response->assertSessionDoesntHaveErrors();
        self::assertSame(2, CourtSlot::query()->where('court_id', $court->getKey())->count());
    }
}
