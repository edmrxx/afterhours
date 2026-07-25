<?php

declare(strict_types=1);

namespace Tests\Feature\Slots;

use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\Setting;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SettingsService;
use App\Services\SlotGeneratorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The "Sync to operating hours" reshape: add the hours that are now open, drop
 * the available slots for hours that are now closed, and never touch a held,
 * booked or blocked slot or a past date.
 */
class SlotSyncToOperatingHoursTest extends TestCase
{
    use RefreshDatabase;

    private SlotGeneratorService $generator;

    /** The 07:00 -> 02:00 window as the hourly start_times it should produce. */
    private const WINDOW_STARTS = [
        '00:00:00', '01:00:00',
        '07:00:00', '08:00:00', '09:00:00', '10:00:00', '11:00:00', '12:00:00',
        '13:00:00', '14:00:00', '15:00:00', '16:00:00', '17:00:00', '18:00:00',
        '19:00:00', '20:00:00', '21:00:00', '22:00:00', '23:00:00',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = app(SlotGeneratorService::class);
    }

    /** @return list<string> sorted start_times for a court on a date */
    private function startsOn(Court $court, string $date): array
    {
        return CourtSlot::query()
            ->where('court_id', $court->getKey())
            ->where('slot_date', $date)
            ->orderBy('start_time')
            ->pluck('start_time')
            ->map(static fn ($t): string => substr((string) $t, 0, 8))
            ->all();
    }

    public function test_it_reshapes_a_6am_10pm_day_into_the_7am_2am_window(): void
    {
        $court = Court::factory()->create();
        $date = Carbon::today()->addDay()->toDateString();

        // The demo shape: hourly 06:00 through 21:00 (6am–10pm).
        foreach (range(6, 21) as $hour) {
            CourtSlot::factory()->forCourt($court)->onDate($date)->atHour($hour)->available()->create();
        }

        $summary = $this->generator->syncCourt($court, '07:00', '02:00');

        // 06:00 dropped; 22:00, 23:00, 00:00, 01:00 added; the rest kept.
        self::assertSame(self::WINDOW_STARTS, $this->startsOn($court, $date));
        self::assertSame(4, $summary['added']);
        self::assertSame(1, $summary['removed']);
    }

    public function test_it_never_removes_a_booked_slot_and_skips_that_date(): void
    {
        $court = Court::factory()->create();
        $date = Carbon::today()->addDay()->toDateString();

        // A paid 6am booking sits outside the new window on an off-grid hour —
        // the whole date must be left exactly as it is.
        CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(6)->booked()->create();
        CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(9)->available()->create();

        $summary = $this->generator->syncCourt($court, '07:00', '02:00');

        self::assertSame(['06:00:00', '09:00:00'], $this->startsOn($court, $date));
        self::assertSame(0, $summary['added']);
        self::assertSame(0, $summary['removed']);
        self::assertSame(1, $summary['skipped_dates']);
    }

    public function test_it_keeps_an_in_window_held_slot_while_reshaping_around_it(): void
    {
        $court = Court::factory()->create();
        $date = Carbon::today()->addDay()->toDateString();

        CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(6)->available()->create();
        $held = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(8)->held()->create();

        $this->generator->syncCourt($court, '07:00', '02:00');

        // 06:00 gone, full window present, and the held 08:00 untouched.
        self::assertSame(self::WINDOW_STARTS, $this->startsOn($court, $date));
        $held->refresh();
        self::assertSame(CourtSlot::STATUS_HELD, $held->status);
    }

    public function test_it_never_touches_a_past_date(): void
    {
        $court = Court::factory()->create();
        $past = Carbon::yesterday()->toDateString();

        CourtSlot::factory()->forCourt($court)->onDate($past)->atHour(6)->available()->create();

        $this->generator->syncCourt($court, '07:00', '02:00');

        self::assertSame(['06:00:00'], $this->startsOn($court, $past));
    }

    public function test_it_does_not_invent_a_schedule_on_a_day_that_had_no_slots(): void
    {
        $court = Court::factory()->create();
        $withSlots = Carbon::today()->addDay()->toDateString();
        $empty = Carbon::today()->addDays(2)->toDateString();

        CourtSlot::factory()->forCourt($court)->onDate($withSlots)->atHour(9)->available()->create();

        $this->generator->syncCourt($court, '07:00', '02:00');

        // The day that had a slot is filled; the empty day stays empty.
        self::assertSame(self::WINDOW_STARTS, $this->startsOn($court, $withSlots));
        self::assertSame([], $this->startsOn($court, $empty));
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $court = Court::factory()->create();
        $date = Carbon::today()->addDay()->toDateString();

        foreach (range(6, 21) as $hour) {
            CourtSlot::factory()->forCourt($court)->onDate($date)->atHour($hour)->available()->create();
        }

        $this->generator->syncCourt($court, '07:00', '02:00');
        $second = $this->generator->syncCourt($court, '07:00', '02:00');

        self::assertSame(0, $second['added']);
        self::assertSame(0, $second['removed']);
        self::assertSame(self::WINDOW_STARTS, $this->startsOn($court, $date));
    }

    public function test_the_admin_endpoint_syncs_every_court(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(SettingsService::class)->set(Setting::GROUP_COMPANY, 'operating_opening_time', '07:00', 'string');
        app(SettingsService::class)->set(Setting::GROUP_COMPANY, 'operating_closing_time', '02:00', 'string');

        $court = Court::factory()->create();
        $date = Carbon::today()->addDay()->toDateString();
        foreach (range(6, 21) as $hour) {
            CourtSlot::factory()->forCourt($court)->onDate($date)->atHour($hour)->available()->create();
        }

        $this->actingAs($admin)->post('/admin/slots/sync-hours')->assertRedirect();

        self::assertSame(self::WINDOW_STARTS, $this->startsOn($court, $date));
    }

    /**
     * Give a slot booking history the way production does: a real reservation
     * that was later cancelled. The slot returns to `available`, but the
     * bookings / booking_slots rows keep referencing it — and both foreign keys
     * are restrictOnDelete, so the row is pinned forever.
     */
    private function pinWithHistory(CourtSlot $slot): void
    {
        $service = app(BookingService::class);

        $booking = $service->reserve([$slot->getKey()], [
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
        ]);

        $service->cancel($booking);

        $slot->refresh();
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $slot->status, 'Sanity: cancel() must free the slot.');
    }

    public function test_an_out_of_window_slot_with_booking_history_is_blocked_not_deleted(): void
    {
        // The exact production failure: a 6am slot outside the new window that a
        // (since released) booking still references. Deleting it violates the
        // FK — it must be blocked instead, with everything else reshaping fine.
        $court = Court::factory()->create();
        $date = Carbon::today()->addDay()->toDateString();

        $pinned = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(6)->available()->create();
        $this->pinWithHistory($pinned);

        // A history-free out-of-window slot on the same date still deletes.
        CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(5)->available()->create();

        $summary = $this->generator->syncCourt($court, '07:00', '02:00');

        self::assertSame(1, $summary['removed'], 'The history-free 5am slot is deleted.');
        self::assertSame(1, $summary['kept_history'], 'The pinned 6am slot is kept.');
        self::assertSame(0, $summary['skipped_dates']);

        $pinned->refresh();
        self::assertSame(CourtSlot::STATUS_BLOCKED, $pinned->status, 'Pinned slot is off the market, not deleted.');

        // The full window was still laid down alongside it.
        $starts = $this->startsOn($court, $date);
        self::assertContains('07:00:00', $starts);
        self::assertContains('01:00:00', $starts);
        self::assertNotContains('05:00:00', $starts);
        self::assertContains('06:00:00', $starts); // the blocked survivor
    }

    public function test_a_blocked_history_slot_does_not_freeze_subsequent_runs(): void
    {
        $court = Court::factory()->create();
        $date = Carbon::today()->addDay()->toDateString();

        $pinned = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(6)->available()->create();
        $this->pinWithHistory($pinned);

        $this->generator->syncCourt($court, '07:00', '02:00');
        $second = $this->generator->syncCourt($court, '07:00', '02:00');

        // The 6am survivor is now a blocked off-grid slot — it must read as
        // inert, not as protection that skips the date forever.
        self::assertSame(0, $second['added']);
        self::assertSame(0, $second['removed']);
        self::assertSame(0, $second['kept_history']);
        self::assertSame(0, $second['skipped_dates']);
    }

    public function test_an_off_grid_blocked_slot_overlapping_the_window_still_skips_the_date(): void
    {
        $court = Court::factory()->create();
        $date = Carbon::today()->addDay()->toDateString();

        // An operator-made 06:30–07:30 blocked slot overlaps the window's
        // 07:00–08:00 — reshaping around it could contradict whatever the
        // operator blocked it for, so the whole date stays untouched.
        CourtSlot::factory()->forCourt($court)->onDate($date)->available()->create([
            'start_time' => '06:30:00',
            'end_time' => '07:30:00',
            'status' => CourtSlot::STATUS_BLOCKED,
        ]);
        CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(6)->available()->create();

        $summary = $this->generator->syncCourt($court, '07:00', '02:00');

        self::assertSame(1, $summary['skipped_dates']);
        self::assertSame(0, $summary['added']);
        self::assertSame(0, $summary['removed']);
        self::assertSame(['06:00:00', '06:30:00'], $this->startsOn($court, $date), 'The skipped date is exactly as it was.');
    }

    public function test_bulk_destroy_keeps_slots_with_booking_history(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $court = Court::factory()->create();
        $date = Carbon::today()->addDay()->toDateString();

        $pinned = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(9)->available()->create();
        $this->pinWithHistory($pinned);
        $plain = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(10)->available()->create();

        // Before the fix this 500'd on the FK; now the pinned slot is reported
        // and skipped while the history-free one still deletes.
        $this->actingAs($admin)
            ->delete('/admin/slots/bulk', ['ids' => [$pinned->getKey(), $plain->getKey()]])
            ->assertRedirect();

        self::assertNotNull(CourtSlot::query()->find($pinned->getKey()), 'Pinned slot survives.');
        self::assertNull(CourtSlot::query()->find($plain->getKey()), 'History-free slot is deleted.');
    }

    public function test_single_destroy_refuses_a_slot_with_booking_history(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $court = Court::factory()->create();
        $pinned = CourtSlot::factory()->forCourt($court)->onDate(Carbon::today()->addDay())->atHour(9)->available()->create();
        $this->pinWithHistory($pinned);

        $this->actingAs($admin)
            ->delete('/admin/slots/'.$pinned->getKey())
            ->assertRedirect()
            ->assertSessionHas('error');

        self::assertNotNull(CourtSlot::query()->find($pinned->getKey()));
    }

    public function test_deleting_a_single_slot_writes_an_audit_entry(): void
    {
        // The race-safe delete uses a query builder, which fires no model event —
        // so destroy() logs the audit explicitly. Guard that it stays logged.
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $slot = CourtSlot::factory()->forCourt(Court::factory()->create())
            ->onDate(Carbon::today()->addDay())->atHour(9)->available()->create();

        $this->actingAs($admin)->delete('/admin/slots/'.$slot->getKey())->assertRedirect();

        self::assertNull(CourtSlot::query()->find($slot->getKey()));
        self::assertDatabaseHas('audit_trails', [
            'module' => 'Slots',
            'action' => 'delete',
        ]);
    }

    public function test_a_multi_slot_bookings_pivot_only_secondary_slot_is_blocked_not_deleted(): void
    {
        // The regression the review flagged: a multi-slot booking sets
        // bookings.court_slot_id to its EARLIEST slot only, so a later
        // out-of-window slot is pinned SOLELY through the booking_slots pivot.
        // If withoutBookingHistory() skipped the pivot, this slot would be
        // deleted and hit the restrictOnDelete FK — the exact production 500.
        $court = Court::factory()->create();
        $day1 = Carbon::today()->addDay()->toDateString();
        $day2 = Carbon::today()->addDays(2)->toDateString();

        // Earliest (in-window) slot becomes the primary (bookings.court_slot_id).
        $primary = CourtSlot::factory()->forCourt($court)->onDate($day1)->atHour(8)->available()->create();
        // Later, out-of-window slot — referenced ONLY by the pivot.
        $pivotOnly = CourtSlot::factory()->forCourt($court)->onDate($day2)->atHour(6)->available()->create();

        $service = app(BookingService::class);
        $booking = $service->reserve([$primary->getKey(), $pivotOnly->getKey()], [
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
        ]);
        $service->cancel($booking);

        // Sanity: the secondary slot really is pivot-only.
        self::assertNotSame($pivotOnly->getKey(), $booking->fresh()->court_slot_id, 'The out-of-window slot must not be the primary.');
        self::assertTrue($pivotOnly->fresh()->hasBookingHistory(), 'The pivot must pin it.');

        $summary = $this->generator->syncCourt($court, '07:00', '02:00');

        $pivotOnly->refresh();
        self::assertSame(CourtSlot::STATUS_BLOCKED, $pivotOnly->status, 'Pivot-pinned slot is blocked, never deleted.');
        self::assertGreaterThanOrEqual(1, $summary['kept_history']);
        self::assertNotNull(CourtSlot::query()->find($pivotOnly->getKey()));
    }

    public function test_single_destroy_refuses_a_pivot_only_history_slot(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $court = Court::factory()->create();
        $primary = CourtSlot::factory()->forCourt($court)->onDate(Carbon::today()->addDay())->atHour(8)->available()->create();
        $pivotOnly = CourtSlot::factory()->forCourt($court)->onDate(Carbon::today()->addDays(2))->atHour(9)->available()->create();

        $service = app(BookingService::class);
        $booking = $service->reserve([$primary->getKey(), $pivotOnly->getKey()], [
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
        ]);
        $service->cancel($booking);

        // Deleting the pivot-only slot must be refused cleanly, never 500.
        $this->actingAs($admin)
            ->delete('/admin/slots/'.$pivotOnly->getKey())
            ->assertRedirect()
            ->assertSessionHas('error');

        self::assertNotNull(CourtSlot::query()->find($pivotOnly->getKey()));
    }

    public function test_blocked_in_window_is_reported_after_hours_widen_back(): void
    {
        $court = Court::factory()->create();
        $date = Carbon::today()->addDay()->toDateString();

        $pinned = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(6)->available()->create();
        $this->pinWithHistory($pinned);

        // Narrow to 07:00 → the pinned 6am is blocked.
        $this->generator->syncCourt($court, '07:00', '02:00');
        self::assertSame(CourtSlot::STATUS_BLOCKED, $pinned->fresh()->status);

        // Widen back to 06:00 → 6am is in-window again but stays blocked (the
        // sync never auto-unblocks). The summary must SURFACE it rather than
        // silently report "nothing changed".
        $widened = $this->generator->syncCourt($court, '06:00', '02:00');

        self::assertGreaterThanOrEqual(1, $widened['blocked_in_window'], 'The in-window blocked slot must be reported.');
        self::assertSame(CourtSlot::STATUS_BLOCKED, $pinned->fresh()->status, 'It is never auto-unblocked.');
    }

    public function test_a_window_shorter_than_one_slot_is_a_no_op_not_a_mass_delete(): void
    {
        // The critical guard: a 30-minute window produces zero valid starts. It
        // must delete NOTHING — an empty whereNotIn() would otherwise wipe the day.
        $court = Court::factory()->create();
        $date = Carbon::today()->addDay()->toDateString();
        foreach (range(6, 21) as $hour) {
            CourtSlot::factory()->forCourt($court)->onDate($date)->atHour($hour)->available()->create();
        }

        $summary = $this->generator->syncCourt($court, '07:00', '07:30');

        self::assertSame(0, $summary['removed']);
        self::assertSame(0, $summary['added']);
        self::assertCount(16, $this->startsOn($court, $date));
    }

    public function test_syncing_all_courts_refuses_a_window_shorter_than_one_slot(): void
    {
        Court::factory()->create();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->generator->syncToOperatingHours('07:00', '07:30');
    }

    public function test_the_endpoint_refuses_a_sub_hour_window_without_deleting(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        // A malformed window written directly (bypassing the settings form's new
        // minimum-window rule) must still never wipe the schedule.
        app(SettingsService::class)->set(Setting::GROUP_COMPANY, 'operating_opening_time', '07:00', 'string');
        app(SettingsService::class)->set(Setting::GROUP_COMPANY, 'operating_closing_time', '07:30', 'string');

        $court = Court::factory()->create();
        $date = Carbon::today()->addDay()->toDateString();
        foreach (range(6, 21) as $hour) {
            CourtSlot::factory()->forCourt($court)->onDate($date)->atHour($hour)->available()->create();
        }

        $this->actingAs($admin)->post('/admin/slots/sync-hours')->assertRedirect();

        // Nothing was deleted.
        self::assertCount(16, $this->startsOn($court, $date));
    }

    public function test_the_endpoint_requires_delete_permission_as_well_as_generate(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(['must_change_password' => false]);
        // Generate but NOT delete — the sync both adds and removes, so it must be refused.
        $user->givePermissionTo('slots.generate', 'slots.view');

        $this->actingAs($user)->post('/admin/slots/sync-hours')->assertForbidden();
    }

    public function test_the_console_command_syncs_every_court(): void
    {
        app(SettingsService::class)->set(Setting::GROUP_COMPANY, 'operating_opening_time', '07:00', 'string');
        app(SettingsService::class)->set(Setting::GROUP_COMPANY, 'operating_closing_time', '02:00', 'string');

        $court = Court::factory()->create();
        $date = Carbon::today()->addDay()->toDateString();
        foreach (range(6, 21) as $hour) {
            CourtSlot::factory()->forCourt($court)->onDate($date)->atHour($hour)->available()->create();
        }

        $this->artisan('slots:sync-hours')->assertSuccessful();

        self::assertSame(self::WINDOW_STARTS, $this->startsOn($court, $date));
    }
}
