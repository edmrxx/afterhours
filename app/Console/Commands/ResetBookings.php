<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CourtSlot;
use App\Services\AuditTrailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Wipe every booking — the deliberate, admin-triggered "clear the test data
 * before we go live with real customers" reset. Nothing about normal
 * operation ever calls this; it only runs from the console, on purpose.
 *
 * Deliberately narrow, on every axis:
 *
 *  - **Bookings.** Every row, regardless of status, INCLUDING ones already
 *    soft-deleted from earlier testing — this is a hard, total wipe of the
 *    `bookings` table, not a status filter. Revenue and booking-count reports
 *    read straight from this table (no separate "sales" table exists), so
 *    they read zero the moment this finishes — that IS the point.
 *  - **booking_slots.** The durable per-slot history pivot — deleted
 *    alongside its booking so no orphaned reference survives.
 *  - **court_slots.** Only the STATUS moves — `held`/`booked` rows return to
 *    `available` and their `held_booking_id` pointer is cleared. The slot
 *    rows themselves (dates, times, prices — the schedule the operator built)
 *    are never touched, and `blocked` slots are left exactly as they are:
 *    some are blocked by deliberate operator choice, and this command has no
 *    way to tell those apart from ones the FK-safety sync parked as blocked
 *    only because deleting them would have violated a booking's history —
 *    history this run is about to erase. Review the Slots screen afterward
 *    if any of those should now be unblocked.
 *  - **Everything else is untouched.** Courts, users, roles, and every
 *    setting (pricing, operating hours, payment, company profile, the owner
 *    notification email) survive exactly as configured.
 *  - **Payment-proof screenshots.** Every non-blank `payment_proof_path` is
 *    deleted from the `public` disk once the row deletion has actually
 *    committed — otherwise a booking still recoverable in the DB (a rolled
 *    back transaction) could have its evidence gone forever. Deliberately
 *    best-effort and outside the transaction: a filesystem delete cannot be
 *    rolled back, so it must never be allowed to abort a DB change that has
 *    already committed. A missing file counts as already gone, not an error.
 *
 * Raw query-builder deletes throughout, not Eloquent: Booking uses
 * SoftDeletes, so `Booking::query()->delete()` would only stamp `deleted_at`
 * on live rows and silently skip rows already soft-deleted — neither
 * actually empties the table. `DB::table(...)` bypasses that scope entirely.
 */
class ResetBookings extends Command
{
    protected $signature = 'bookings:reset {--force : Skip the confirmation prompt}';

    protected $description = 'Delete every booking and free held/booked slots back to available — courts, the slot schedule, and all settings are left untouched';

    public function handle(AuditTrailService $audit): int
    {
        $bookingCount = (int) DB::table('bookings')->count();
        $toFree = CourtSlot::query()
            ->whereIn('status', [CourtSlot::STATUS_HELD, CourtSlot::STATUS_BOOKED])
            ->count();

        // Gate on both, not bookings alone: held_booking_id carries no foreign
        // key (see court_slots migration), so a slot can in principle be stuck
        // held/booked with no live booking behind it — a prior manual fix, an
        // edge case this command hasn't seen yet. That slot deserves freeing
        // even when there is genuinely nothing left to delete.
        if ($bookingCount === 0 && $toFree === 0) {
            $this->info('Nothing to reset — no bookings and no held/booked slots.');

            return self::SUCCESS;
        }

        $bookingSlotCount = (int) DB::table('booking_slots')->count();
        $blockedCount = (int) CourtSlot::query()->where('status', CourtSlot::STATUS_BLOCKED)->count();

        $this->table(
            ['What', 'Count'],
            [
                ['Bookings to delete', number_format($bookingCount)],
                ['Booking-slot records to delete', number_format($bookingSlotCount)],
                ['Court slots to free back to available', number_format($toFree)],
                ['Blocked slots left untouched', number_format($blockedCount)],
            ],
        );

        $this->warn('This permanently deletes every booking (and any payment-proof screenshots on disk). Revenue and booking reports will read zero. This cannot be undone.');
        $this->line('NOT touched: courts, the slot schedule (dates/times/prices), users, roles, and every setting (pricing, operating hours, payment, company, owner notification email).');
        $this->line('The counts above are a snapshot — a booking made in the next few seconds is still caught and deleted, just not reflected in this preview.');

        if (! $this->option('force') && ! $this->confirm('Proceed?', false)) {
            $this->comment('Aborted — nothing was changed.');

            return self::SUCCESS;
        }

        $deletedBookings = 0;
        $deletedBookingSlots = 0;
        $freedSlots = 0;
        $proofPaths = [];

        try {
            DB::transaction(function () use (&$deletedBookings, &$deletedBookingSlots, &$freedSlots, &$proofPaths): void {
                // Read inside the same transaction, immediately before the
                // delete, so the path list is exactly the set of rows this
                // statement is about to remove — not a pre-transaction snapshot
                // that could drift from what actually gets deleted.
                $proofPaths = DB::table('bookings')
                    ->whereNotNull('payment_proof_path')
                    ->where('payment_proof_path', '!=', '')
                    ->pluck('payment_proof_path')
                    ->all();

                // booking_slots first for clarity, though bookings' own
                // cascadeOnDelete on booking_slots.booking_id would remove them
                // anyway once the parent row is gone.
                $deletedBookingSlots = DB::table('booking_slots')->delete();

                $deletedBookings = DB::table('bookings')->delete();

                $freedSlots = CourtSlot::query()
                    ->whereIn('status', [CourtSlot::STATUS_HELD, CourtSlot::STATUS_BOOKED])
                    ->update([
                        'status' => CourtSlot::STATUS_AVAILABLE,
                        'held_booking_id' => null,
                        'updated_at' => now(),
                    ]);
            });
        } catch (Throwable $e) {
            Log::error('bookings:reset aborted.', ['error' => $e->getMessage()]);
            $this->error('Reset aborted — nothing was changed: '.$e->getMessage());

            return self::FAILURE;
        }

        // Only after the transaction has actually committed: a filesystem
        // delete cannot be rolled back, so it must never run against rows that
        // might still be reverted. Best-effort — a storage hiccup here must not
        // make an already-committed, already-reported reset look like it failed.
        if ($proofPaths !== []) {
            try {
                Storage::disk('public')->delete($proofPaths);
            } catch (Throwable $e) {
                Log::error('bookings:reset — could not delete one or more payment-proof files.', [
                    'error' => $e->getMessage(),
                    'count' => count($proofPaths),
                ]);
                $this->warn('Bookings were deleted, but some payment-proof files could not be removed from storage — check the log.');
            }
        }

        AuditTrailService::asSystem(function () use ($audit, $deletedBookings, $deletedBookingSlots, $freedSlots, $proofPaths): void {
            $audit->log(
                module: 'Bookings',
                action: 'delete',
                description: sprintf(
                    'Bulk reset via console: deleted %s booking(s) and %s booking-slot record(s), freed %s court slot(s) back to available.',
                    number_format($deletedBookings),
                    number_format($deletedBookingSlots),
                    number_format($freedSlots),
                ),
                oldValues: [
                    'bookings_deleted' => $deletedBookings,
                    'booking_slots_deleted' => $deletedBookingSlots,
                    'slots_freed' => $freedSlots,
                    'proof_files_deleted' => count($proofPaths),
                ],
            );
        }, 'Console: bookings:reset');

        $this->info(sprintf(
            'Done — %s booking(s) deleted, %s booking-slot record(s) deleted, %s court slot(s) freed back to available, %s payment-proof file(s) removed.',
            number_format($deletedBookings),
            number_format($deletedBookingSlots),
            number_format($freedSlots),
            number_format(count($proofPaths)),
        ));

        Log::info('bookings:reset completed.', [
            'bookings_deleted' => $deletedBookings,
            'booking_slots_deleted' => $deletedBookingSlots,
            'slots_freed' => $freedSlots,
            'proof_files_deleted' => count($proofPaths),
        ]);

        return self::SUCCESS;
    }
}
