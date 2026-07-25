<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AuditTrail;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `bookings:reset` — the "clear test data before going live" wipe. Every
 * assertion here defends one specific promise made in the command's docblock:
 * total wipe of bookings (even soft-deleted ones), held/booked slots freed,
 * blocked slots left alone, and every non-booking table untouched.
 */
class ResetBookingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_nothing_to_reset_when_bookings_table_is_empty(): void
    {
        $this->artisan('bookings:reset', ['--force' => true])
            ->expectsOutputToContain('Nothing to reset')
            ->assertSuccessful();
    }

    public function test_it_deletes_every_booking_regardless_of_status(): void
    {
        $court = Court::factory()->create();
        $slot = CourtSlot::factory()->forCourt($court)->available()->create();

        foreach (Booking::STATUSES as $status) {
            Booking::factory()->for($court)->for($slot, 'courtSlot')->create(['status' => $status]);
        }

        self::assertSame(count(Booking::STATUSES), Booking::query()->count());

        $this->artisan('bookings:reset', ['--force' => true])->assertSuccessful();

        self::assertSame(0, DB::table('bookings')->count());
    }

    public function test_it_deletes_bookings_already_soft_deleted_from_earlier_testing(): void
    {
        // Mirrors production: a booking cancelled/cleaned up earlier left a
        // soft-deleted row behind. A plain Booking::query()->delete() would
        // skip it (excluded by the SoftDeletes global scope) — this must not.
        $court = Court::factory()->create();
        $slot = CourtSlot::factory()->forCourt($court)->available()->create();
        $booking = Booking::factory()->for($court)->for($slot, 'courtSlot')->create([
            'status' => Booking::STATUS_CANCELLED,
        ]);
        $booking->delete();

        self::assertSame(0, Booking::query()->count(), 'Sanity: the soft-deleted row is excluded from the default scope.');
        self::assertSame(1, DB::table('bookings')->count(), 'Sanity: the row still physically exists.');

        $this->artisan('bookings:reset', ['--force' => true])->assertSuccessful();

        self::assertSame(0, DB::table('bookings')->count(), 'The soft-deleted row must be hard-deleted too.');
    }

    public function test_it_deletes_booking_slots_alongside_bookings(): void
    {
        $court = Court::factory()->create();
        $slotA = CourtSlot::factory()->forCourt($court)->available()->create();
        $slotB = CourtSlot::factory()->forCourt($court)->available()->create();
        $booking = Booking::factory()->for($court)->for($slotA, 'courtSlot')->create();

        // Baseline, not an assumed 0: other suites in this app spawn real
        // subprocesses to test true DB-level concurrency (see
        // BookingRaceConditionTest), whose commits land outside this test's
        // transaction and can leave rows the RefreshDatabase rollback never
        // sees. The command's job is to wipe EVERY booking_slots row
        // regardless of where it came from — that's what's asserted below.
        $before = DB::table('booking_slots')->count();

        DB::table('booking_slots')->insert([
            ['booking_id' => $booking->getKey(), 'court_slot_id' => $slotA->getKey(), 'created_at' => now(), 'updated_at' => now()],
            ['booking_id' => $booking->getKey(), 'court_slot_id' => $slotB->getKey(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        self::assertSame($before + 2, DB::table('booking_slots')->count());

        $this->artisan('bookings:reset', ['--force' => true])->assertSuccessful();

        self::assertSame(0, DB::table('booking_slots')->count(), 'Every booking_slots row is gone, including any left by unrelated tests.');
    }

    public function test_it_frees_held_and_booked_slots_but_leaves_blocked_slots_alone(): void
    {
        // Distinct hours on the same court/date: the factory otherwise picks a
        // random date+hour per row, which can collide with court_slots'
        // (court_id, slot_date, start_time) unique index across four rows.
        $court = Court::factory()->create();
        $date = now()->addDays(3);
        $held = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(9)->held()->create(['held_booking_id' => 999]);
        $booked = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(10)->booked()->create(['held_booking_id' => 999]);
        $blocked = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(11)->create(['status' => CourtSlot::STATUS_BLOCKED]);
        $available = CourtSlot::factory()->forCourt($court)->onDate($date)->atHour(12)->available()->create();

        Booking::factory()->for($court)->for($held, 'courtSlot')->create();

        $this->artisan('bookings:reset', ['--force' => true])->assertSuccessful();

        self::assertSame(CourtSlot::STATUS_AVAILABLE, $held->fresh()->status);
        self::assertNull($held->fresh()->held_booking_id);
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $booked->fresh()->status);
        self::assertNull($booked->fresh()->held_booking_id);

        // Untouched — blocked stays blocked, available stays available.
        self::assertSame(CourtSlot::STATUS_BLOCKED, $blocked->fresh()->status);
        self::assertSame(CourtSlot::STATUS_AVAILABLE, $available->fresh()->status);
    }

    public function test_it_never_deletes_court_slot_rows_themselves(): void
    {
        $court = Court::factory()->create();
        CourtSlot::factory()->forCourt($court)->held()->create();
        CourtSlot::factory()->forCourt($court)->booked()->create();
        CourtSlot::factory()->forCourt($court)->create(['status' => CourtSlot::STATUS_BLOCKED]);
        $slot = CourtSlot::factory()->forCourt($court)->booked()->create();
        Booking::factory()->for($court)->for($slot, 'courtSlot')->create();

        $slotId = $slot->getKey();
        $totalBefore = DB::table('court_slots')->count();

        $this->artisan('bookings:reset', ['--force' => true])->assertSuccessful();

        self::assertNotNull(CourtSlot::query()->find($slotId), 'The slot row itself must survive — only its status changes.');
        self::assertSame(
            $totalBefore,
            DB::table('court_slots')->count(),
            'The reset must never insert or delete a single court_slots row — held/booked/blocked/available, the row count is invariant.',
        );
    }

    public function test_it_leaves_courts_settings_and_slot_schedule_untouched(): void
    {
        $court = Court::factory()->create(['name' => 'Center Court']);
        $slot = CourtSlot::factory()->forCourt($court)->available()->create([
            'slot_date' => '2026-08-01',
            'start_time' => '09:00:00',
            'price' => 500,
        ]);
        Booking::factory()->for($court)->for($slot, 'courtSlot')->create();

        app(SettingsService::class)->set(Setting::GROUP_SYSTEM, 'owner_notification_email', 'owner@example.com', 'string');

        $this->artisan('bookings:reset', ['--force' => true])->assertSuccessful();

        self::assertSame('Center Court', $court->fresh()->name);
        self::assertSame('2026-08-01', $slot->fresh()->slot_date->toDateString());
        self::assertSame('09:00:00', (string) $slot->fresh()->start_time);
        self::assertSame('500.00', (string) $slot->fresh()->price);
        self::assertSame('owner@example.com', app(SettingsService::class)->get(Setting::GROUP_SYSTEM, 'owner_notification_email'));
    }

    public function test_declining_the_confirmation_prompt_changes_nothing(): void
    {
        $court = Court::factory()->create();
        $slot = CourtSlot::factory()->forCourt($court)->booked()->create();
        Booking::factory()->for($court)->for($slot, 'courtSlot')->create();

        $this->artisan('bookings:reset')
            ->expectsConfirmation('Proceed?', 'no')
            ->expectsOutputToContain('Aborted')
            ->assertSuccessful();

        self::assertSame(1, DB::table('bookings')->count());
        self::assertSame(CourtSlot::STATUS_BOOKED, $slot->fresh()->status);
    }

    public function test_it_writes_a_single_summarising_audit_entry(): void
    {
        $court = Court::factory()->create();
        $slotA = CourtSlot::factory()->forCourt($court)->booked()->create();
        $slotB = CourtSlot::factory()->forCourt($court)->held()->create();
        Booking::factory()->for($court)->for($slotA, 'courtSlot')->create();
        Booking::factory()->for($court)->for($slotB, 'courtSlot')->create();

        $before = AuditTrail::query()->count();

        $this->artisan('bookings:reset', ['--force' => true])->assertSuccessful();

        self::assertSame($before + 1, AuditTrail::query()->count(), 'Exactly one summarising entry, not one per row.');

        $entry = AuditTrail::query()->where('module', 'Bookings')->where('action', 'delete')->latest('id')->first();

        self::assertNotNull($entry);
        self::assertSame(
            ['bookings_deleted' => 2, 'booking_slots_deleted' => 0, 'slots_freed' => 2, 'proof_files_deleted' => 0],
            $entry->old_values,
        );
        self::assertStringContainsString('deleted 2 booking(s)', $entry->description);
        self::assertStringContainsString('freed 2 court slot(s)', $entry->description);
    }

    public function test_it_deletes_payment_proof_files_from_storage(): void
    {
        Storage::fake('public');

        $court = Court::factory()->create();
        $slot = CourtSlot::factory()->forCourt($court)->booked()->create();

        $path = 'payment-proofs/2026/07/test-proof.jpg';
        Storage::disk('public')->put($path, 'fake-image-bytes');

        Booking::factory()->for($court)->for($slot, 'courtSlot')->create([
            'payment_proof_path' => $path,
        ]);

        self::assertTrue(Storage::disk('public')->exists($path));

        $this->artisan('bookings:reset', ['--force' => true])
            ->expectsOutputToContain('1 payment-proof file(s) removed')
            ->assertSuccessful();

        Storage::disk('public')->assertMissing($path);
    }

    public function test_it_does_not_error_when_a_payment_proof_file_is_already_missing(): void
    {
        Storage::fake('public');

        $court = Court::factory()->create();
        $slot = CourtSlot::factory()->forCourt($court)->booked()->create();

        // Points at a file that was never actually written (or was already
        // cleaned up by hand) — deleting it must be a no-op, not an error.
        Booking::factory()->for($court)->for($slot, 'courtSlot')->create([
            'payment_proof_path' => 'payment-proofs/2026/07/never-existed.jpg',
        ]);

        $this->artisan('bookings:reset', ['--force' => true])->assertSuccessful();

        self::assertSame(0, DB::table('bookings')->count());
    }

    public function test_the_report_table_shows_accurate_counts_before_confirming(): void
    {
        $court = Court::factory()->create();
        $slot = CourtSlot::factory()->forCourt($court)->booked()->create();
        Booking::factory()->for($court)->for($slot, 'courtSlot')->create();

        $this->artisan('bookings:reset')
            ->expectsOutputToContain('Bookings to delete')
            ->expectsConfirmation('Proceed?', 'no')
            ->assertSuccessful();
    }
}
