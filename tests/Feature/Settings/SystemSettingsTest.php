<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\AuditTrail;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\Setting;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SlotGeneratorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function staff(): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole(RolePermissionSeeder::ROLE_STAFF);

        return $user;
    }

    /**
     * A complete, valid settings payload. The screen submits every field at
     * once, so the tests do too — override only the field under test.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'booking_hold_minutes' => 30,
            'booking_verification_hold_minutes' => 720,
            'booking_code_prefix' => 'PHEA',
            'pricing_non_peak_rate' => 450,
            'pricing_peak_rate' => 500,
            'pricing_peak_start' => '16:00',
            'pricing_peak_end' => '02:00',
        ], $overrides);
    }

    public function test_a_user_without_settings_update_cannot_change_booking_hold_times(): void
    {
        $this->actingAs($this->staff())->put('/admin/settings/system', $this->payload([
            'booking_hold_minutes' => 45,
            'booking_verification_hold_minutes' => 900,
        ]))->assertForbidden();
    }

    public function test_an_admin_can_update_the_booking_hold_times(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings/system', $this->payload([
            'booking_hold_minutes' => 45,
            'booking_verification_hold_minutes' => 900,
            'booking_code_prefix' => 'TPR',
        ]))->assertRedirect();

        self::assertSame('45', Setting::query()->group(Setting::GROUP_SYSTEM)->key('booking_hold_minutes')->value('value'));
        self::assertSame('900', Setting::query()->group(Setting::GROUP_SYSTEM)->key('booking_verification_hold_minutes')->value('value'));
        self::assertSame('TPR', Setting::query()->group(Setting::GROUP_SYSTEM)->key('booking_code_prefix')->value('value'));

        $entry = AuditTrail::query()
            ->where('module', 'Settings')
            ->where('action', 'update')
            ->latest('id')
            ->first();

        self::assertNotNull($entry, 'Saving booking settings must write a Settings/update audit entry.');
    }

    public function test_the_code_prefix_is_stored_uppercase_regardless_of_how_it_was_typed(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings/system', $this->payload([
            'booking_code_prefix' => 'court',
        ]))->assertRedirect();

        self::assertSame('COURT', Setting::query()->group(Setting::GROUP_SYSTEM)->key('booking_code_prefix')->value('value'));
    }

    public function test_a_code_prefix_with_a_hyphen_or_space_is_rejected(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings/system', $this->payload([
            'booking_code_prefix' => 'PH-EA',
        ]))->assertInvalid(['booking_code_prefix']);
    }

    public function test_an_admin_can_update_the_court_pricing(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings/system', $this->payload([
            'pricing_non_peak_rate' => 480,
            'pricing_peak_rate' => 550,
            'pricing_peak_start' => '17:00',
            'pricing_peak_end' => '01:00',
        ]))->assertRedirect();

        $group = fn (string $key): ?string => Setting::query()->group(Setting::GROUP_SYSTEM)->key($key)->value('value');

        self::assertSame('480.00', $group('pricing_non_peak_rate'));
        self::assertSame('550.00', $group('pricing_peak_rate'));
        self::assertSame('17:00', $group('pricing_peak_start'));
        self::assertSame('01:00', $group('pricing_peak_end'));
    }

    public function test_a_pricing_window_with_the_same_start_and_end_is_rejected(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings/system', $this->payload([
            'pricing_peak_start' => '16:00',
            'pricing_peak_end' => '16:00',
        ]))->assertInvalid(['pricing_peak_end']);
    }

    public function test_saving_a_new_rate_reprices_every_courts_available_future_slots(): void
    {
        $courtA = Court::factory()->create();
        $courtB = Court::factory()->create();

        $date = Carbon::today()->addDay();

        // Stale prices on both courts, as if generated under an older rate.
        $nonPeakA = CourtSlot::factory()->forCourt($courtA)->onDate($date)->atHour(9)->available()->create(['price' => 111.00]);
        $peakB = CourtSlot::factory()->forCourt($courtB)->onDate($date)->atHour(18)->available()->create(['price' => 111.00]);

        // Slots that must never move: a held one, and a past one.
        $held = CourtSlot::factory()->forCourt($courtA)->onDate($date)->atHour(20)->held()->create(['price' => 111.00]);
        $past = CourtSlot::factory()->forCourt($courtB)->onDate(Carbon::yesterday())->atHour(9)->available()->create(['price' => 111.00]);

        $this->actingAs($this->admin())->put('/admin/settings/system', $this->payload([
            'pricing_non_peak_rate' => 480,
            'pricing_peak_rate' => 550,
        ]))->assertRedirect();

        self::assertSame(480.00, (float) $nonPeakA->refresh()->price, 'Court A non-peak slot picks up the new rate.');
        self::assertSame(550.00, (float) $peakB->refresh()->price, 'Court B peak slot picks up the new rate.');
        self::assertSame(111.00, (float) $held->refresh()->price, 'A held slot keeps the price the customer is mid-checkout on.');
        self::assertSame(111.00, (float) $past->refresh()->price, 'A past slot is never repriced.');
    }

    public function test_saving_without_changing_the_pricing_does_not_reprice_slots(): void
    {
        $court = Court::factory()->create();
        $slot = CourtSlot::factory()->forCourt($court)->onDate(Carbon::today()->addDay())->atHour(9)->available()->create(['price' => 111.00]);

        // The pricing fields match the shipped defaults, so only the hold time
        // changes — the schedule must be left exactly as it is.
        $this->actingAs($this->admin())->put('/admin/settings/system', $this->payload([
            'booking_hold_minutes' => 45,
            'booking_verification_hold_minutes' => 900,
        ]))->assertRedirect();

        self::assertSame(111.00, (float) $slot->refresh()->price, 'An unrelated settings save must not reprice anything.');
    }

    public function test_a_failed_auto_reprice_rolls_back_the_rate_change(): void
    {
        $court = Court::factory()->create();
        $slot = CourtSlot::factory()->forCourt($court)->onDate(Carbon::today()->addDay())->atHour(9)->available()->create(['price' => 111.00]);

        // Force the bulk reprice to blow up partway through the request.
        $this->mock(SlotGeneratorService::class, function ($mock): void {
            $mock->shouldReceive('repriceAll')->andThrow(new \RuntimeException('boom'));
        });

        $this->actingAs($this->admin())->put('/admin/settings/system', $this->payload([
            'pricing_non_peak_rate' => 480,
        ]))->assertStatus(500);

        // The rate write shares the reprice's transaction, so it rolled back too —
        // a plain re-save is then a genuine retry, not a pricingChanged() no-op.
        self::assertNotSame(
            '480.00',
            Setting::query()->group(Setting::GROUP_SYSTEM)->key('pricing_non_peak_rate')->value('value'),
            'A failed reprice must roll the rate change back with it.',
        );

        self::assertSame(111.00, (float) $slot->refresh()->price, 'The slot must be left at its original price.');
    }

    public function test_an_auto_reprice_writes_its_own_slots_audit_entry(): void
    {
        $court = Court::factory()->create();
        CourtSlot::factory()->forCourt($court)->onDate(Carbon::today()->addDay())->atHour(9)->available()->create(['price' => 111.00]);

        $this->actingAs($this->admin())->put('/admin/settings/system', $this->payload([
            'pricing_non_peak_rate' => 480,
        ]))->assertRedirect();

        $entry = AuditTrail::query()
            ->where('module', 'Slots')
            ->where('action', 'update')
            ->latest('id')
            ->first();

        self::assertNotNull($entry, 'An auto-reprice that moved slots must leave a Slots/update audit entry.');
    }

    public function test_a_saved_code_prefix_is_used_for_new_bookings(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings/system', $this->payload([
            'booking_code_prefix' => 'TPR',
        ]))->assertRedirect();

        $court = Court::factory()->create();
        $slot = CourtSlot::factory()->forCourt($court)->onDate(Carbon::today()->addDay())->atHour(9)->available()->create();

        $booking = app(BookingService::class)->reserve([$slot->getKey()], [
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
        ]);

        self::assertStringStartsWith('TPR-', $booking->code);
    }

    public function test_the_verification_hold_cannot_be_shorter_than_the_reservation_hold(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings/system', $this->payload([
            'booking_hold_minutes' => 60,
            'booking_verification_hold_minutes' => 30,
        ]))->assertInvalid(['booking_verification_hold_minutes']);
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings/system', $this->payload([
            'booking_hold_minutes' => 0,
            'booking_verification_hold_minutes' => 900,
        ]))->assertInvalid(['booking_hold_minutes']);
    }

    public function test_a_saved_hold_time_is_honoured_by_new_reservations(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings/system', $this->payload([
            'booking_hold_minutes' => 90,
            'booking_verification_hold_minutes' => 900,
        ]))->assertRedirect();

        $court = Court::factory()->create();
        $slot = CourtSlot::factory()->forCourt($court)->onDate(Carbon::today()->addDay())->atHour(9)->available()->create();

        $booking = app(BookingService::class)->reserve([$slot->getKey()], [
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
        ]);

        self::assertNotNull($booking->hold_expires_at);
        self::assertEqualsWithDelta(now()->addMinutes(90)->getTimestamp(), $booking->hold_expires_at->getTimestamp(), 5);
    }
}
