<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Returns lapsed holds to the market.
 *
 * Runs every minute (see bootstrap/app.php). Three properties matter:
 *
 *  - **Idempotent.** Every booking is re-checked under a row lock inside
 *    `BookingService::expire()`, so a second run — or two overlapping runs —
 *    cannot double-release or trample a customer who paid in the meantime.
 *  - **Flat memory.** `chunkById()` walks the index in keyed pages instead of
 *    hydrating the whole backlog. `offset`-style chunking would also skip rows
 *    here, because expiring a booking removes it from the very result set
 *    being paginated; keyset chunking is correct as well as cheap.
 *  - **Fault tolerant.** One poisoned row is logged and stepped over; it must
 *    not strand every hold behind it.
 */
class ReleaseExpiredBookingHolds extends Command
{
    protected $signature = 'bookings:release-holds
                            {--chunk=200 : Rows to load per pass}
                            {--dry-run : Report what would be released without touching anything}';

    protected $description = 'Expire bookings whose payment hold has lapsed and return their slots to available';

    public function handle(BookingService $bookings): int
    {
        $chunk = max(25, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $scanned = 0;
        $released = 0;
        $skipped = 0;
        $failed = 0;

        try {
            Booking::expiredHolds()
                ->select(['id', 'code', 'status', 'court_id', 'court_slot_id', 'customer_name', 'customer_phone', 'customer_email', 'hold_expires_at'])
                ->orderBy('id')
                ->chunkById($chunk, function ($batch) use ($bookings, $dryRun, &$scanned, &$released, &$skipped, &$failed): void {
                    foreach ($batch as $booking) {
                        $scanned++;

                        if ($dryRun) {
                            $this->line(sprintf(
                                '  would release <comment>%s</comment> (held until %s)',
                                (string) $booking->code,
                                (string) $booking->hold_expires_at,
                            ));
                            $released++;

                            continue;
                        }

                        try {
                            // Lost the race with a real customer? Not an error.
                            $bookings->expire($booking) ? $released++ : $skipped++;
                        } catch (Throwable $e) {
                            $failed++;

                            Log::error('Failed to release an expired booking hold.', [
                                'booking' => $booking->code ?? $booking->getKey(),
                                'error' => $e->getMessage(),
                            ]);

                            $this->error(sprintf('  %s could not be released: %s', (string) $booking->code, $e->getMessage()));
                        }
                    }
                });
        } catch (Throwable $e) {
            Log::error('bookings:release-holds aborted.', ['error' => $e->getMessage()]);
            $this->error('Release run aborted: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->report($scanned, $released, $skipped, $failed, $dryRun);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function report(int $scanned, int $released, int $skipped, int $failed, bool $dryRun): void
    {
        if ($scanned === 0) {
            // Keep the every-minute schedule quiet when there is nothing to do.
            $this->line('No expired holds. Nothing to release.');

            return;
        }

        $verb = $dryRun ? 'would be released' : 'released';

        $this->info(sprintf('%d expired hold(s) scanned, %d %s.', $scanned, $released, $verb));

        if ($skipped > 0) {
            $this->line(sprintf('  %d skipped (already settled by a customer or another run).', $skipped));
        }

        if ($failed > 0) {
            $this->warn(sprintf('  %d failed — see the log for details.', $failed));
        }

        if (! $dryRun && $released > 0) {
            Log::info('Expired booking holds released.', ['count' => $released]);
        }
    }
}
