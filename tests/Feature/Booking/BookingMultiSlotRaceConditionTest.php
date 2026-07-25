<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The multi-slot sibling of BookingRaceConditionTest: two customers request
 * OVERLAPPING sets of slots (not just a single shared slot) at essentially
 * the same instant, in two genuinely independent OS processes, each with its
 * own PDO connection. This exercises the deterministic ascending-id lock
 * order BookingService::reserve() imposes on multi-slot requests — the
 * property specifically built to let two overlapping multi-slot requests
 * fail safely against each other instead of deadlocking.
 *
 * Deliberately NOT wrapped in the usual per-test database transaction (see
 * $connectionsToTransact below): the child processes need to see committed
 * rows through their own connections, and the parent needs to read what they
 * committed.
 */
class BookingMultiSlotRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Disables RefreshDatabase's automatic transaction-per-test wrapping
     * (see connectionsToTransact() in the trait) for THIS class only, while
     * still benefiting from the one-time migrate:fresh the trait performs.
     * Necessary so the child processes' independent connections can see
     * rows this test commits, and vice versa.
     *
     * @var list<string>
     */
    protected $connectionsToTransact = [];

    /** @var list<int> */
    private array $createdCourtIds = [];

    /** @var list<string> */
    private array $scratchFiles = [];

    private int $auditBaselineId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Baseline so tearDown can purge only what THIS test wrote — reserve()
        // writes explicit audit entries even under withoutAuditing() (that
        // suppression only mutes the Auditable trait's automatic hooks).
        $this->auditBaselineId = (int) (DB::table('audit_trails')->max('id') ?? 0);
    }

    protected function tearDown(): void
    {
        // This class writes directly (no wrapping transaction to roll back),
        // so it must clean up after itself to avoid polluting later tests.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('bookings')->whereIn('court_id', $this->createdCourtIds)->delete();
        DB::table('court_slots')->whereIn('court_id', $this->createdCourtIds)->delete();
        DB::table('courts')->whereIn('id', $this->createdCourtIds)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        DB::table('audit_trails')->where('id', '>', $this->auditBaselineId)->delete();

        foreach ($this->scratchFiles as $file) {
            @unlink($file);
            @unlink($file.'.ready');
        }

        parent::tearDown();
    }

    /**
     * Customer A requests slots [1,2,3]; Customer B requests slots [3,4,5],
     * deliberately overlapping on slot 3. Both are released by the same
     * barrier file at essentially the same instant. Exactly one of them must
     * win ALL of their originally requested slots; the loser's request must
     * be rolled back atomically — including the two slots the loser did NOT
     * contend with anyone over.
     */
    public function test_two_concurrent_multi_slot_reserves_with_overlap_exactly_one_wins_atomically(): void
    {
        $court = Court::factory()->create();
        $this->createdCourtIds[] = $court->getKey();

        $date = Carbon::today()->addDay();

        // Five slots, in ascending start-time order, ids assigned in creation
        // order — slot 1 (earliest) ... slot 5 (latest).
        $slots = [];
        for ($hour = 9; $hour < 14; $hour++) {
            $slots[] = CourtSlot::factory()
                ->forCourt($court)
                ->onDate($date)
                ->atHour($hour)
                ->available()
                ->create();
        }

        [$slot1, $slot2, $slot3, $slot4, $slot5] = $slots;

        $requestA = [$slot1->getKey(), $slot2->getKey(), $slot3->getKey()];
        $requestB = [$slot3->getKey(), $slot4->getKey(), $slot5->getKey()];

        $dir = sys_get_temp_dir();
        $barrier = $dir.'/phea_multi_race_barrier_'.uniqid('', true);
        $outA = $dir.'/phea_multi_race_a_'.uniqid('', true).'.json';
        $outB = $dir.'/phea_multi_race_b_'.uniqid('', true).'.json';
        $this->scratchFiles = [$outA, $outB, $barrier];

        $script = __DIR__.'/../../Support/concurrent_multi_slot_reserve_script.php';
        $php = (new PhpExecutableFinder)->find() ?: PHP_BINARY;

        $env = $this->childEnvironment();

        $procA = new Process([
            $php, $script, implode(',', $requestA), 'Racer One', '09171234561', $barrier, $outA,
        ], null, $env);
        $procB = new Process([
            $php, $script, implode(',', $requestB), 'Racer Two', '09171234562', $barrier, $outB,
        ], null, $env);

        $procA->setTimeout(30);
        $procB->setTimeout(30);

        $procA->start();
        $procB->start();

        // Wait until both workers have finished booting Laravel and are
        // parked on the barrier, then release them together.
        $bootDeadline = microtime(true) + 20;

        while ((! file_exists($outA.'.ready') || ! file_exists($outB.'.ready')) && microtime(true) < $bootDeadline) {
            usleep(2000);
        }

        file_put_contents($barrier, '1');

        $procA->wait();
        $procB->wait();

        // No deadlock/timeout: both processes must exit cleanly within the
        // same timeout budget the single-slot race test uses. A deadlock
        // would surface here as a non-zero exit / timeout exception from
        // Symfony Process, not a graceful BookingException in the JSON.
        self::assertSame(0, $procA->getExitCode(), 'Worker A crashed or deadlocked: '.$procA->getErrorOutput());
        self::assertSame(0, $procB->getExitCode(), 'Worker B crashed or deadlocked: '.$procB->getErrorOutput());

        self::assertFileExists($outA, 'Worker A produced no output. Stderr: '.$procA->getErrorOutput());
        self::assertFileExists($outB, 'Worker B produced no output. Stderr: '.$procB->getErrorOutput());

        $resultA = json_decode((string) file_get_contents($outA), true);
        $resultB = json_decode((string) file_get_contents($outB), true);

        $resultsByRequest = [
            'A' => ['result' => $resultA, 'requested' => $requestA],
            'B' => ['result' => $resultB, 'requested' => $requestB],
        ];

        $results = [$resultA, $resultB];
        $successes = array_values(array_filter($results, fn (array $r): bool => $r['success'] === true));
        $failures = array_values(array_filter($results, fn (array $r): bool => $r['success'] === false));

        self::assertCount(1, $successes, 'Exactly one of the two overlapping multi-slot reserve() calls must win. Got: '.json_encode($results));
        self::assertCount(1, $failures, 'Exactly one of the two overlapping multi-slot reserve() calls must lose. Got: '.json_encode($results));

        self::assertSame(BookingException::class, $failures[0]['exception'], 'Loser must fail with BookingException, not a deadlock/timeout/unexpected error.');

        // Identify winner/loser by matching the success result back to its
        // originally requested slot set (not by array position — Symfony
        // Process scheduling order is not guaranteed).
        $winnerKey = $resultsByRequest['A']['result']['success'] === true ? 'A' : 'B';
        $loserKey = $winnerKey === 'A' ? 'B' : 'A';

        $winnerRequested = $resultsByRequest[$winnerKey]['requested'];
        $loserRequested = $resultsByRequest[$loserKey]['requested'];
        $winnerResult = $resultsByRequest[$winnerKey]['result'];

        sort($winnerRequested);

        // The winner's booking holds ALL THREE originally requested slots —
        // not a partial subset. This is read from the worker's self-report
        // AND cross-checked against the database directly below.
        $heldByWinner = $winnerResult['held_slot_ids'];
        sort($heldByWinner);
        self::assertSame($winnerRequested, $heldByWinner, 'Winner must hold exactly all of their originally requested slots.');

        // --- Ground truth: read the actual final row states directly from ---
        // --- the database. Do not trust the worker script's self-report.  ---

        $bookingId = $winnerResult['booking_id'];

        $winningBooking = Booking::query()->find($bookingId);
        self::assertNotNull($winningBooking, 'The winning booking must actually exist in the database.');
        self::assertSame(Booking::STATUS_AWAITING_PAYMENT, $winningBooking->status);

        $allFiveIds = array_map(fn (CourtSlot $s): int => $s->getKey(), $slots);
        $freshSlots = CourtSlot::query()->whereIn('id', $allFiveIds)->orderBy('id')->get()->keyBy('id');

        // Every slot the winner requested is HELD and owned by the winning
        // booking — including the contested slot 3.
        foreach ($winnerRequested as $id) {
            $row = $freshSlots->get($id);
            self::assertNotNull($row, "Slot {$id} must still exist.");
            self::assertSame(CourtSlot::STATUS_HELD, $row->status, "Slot {$id} (won by customer {$winnerKey}) must be held.");
            self::assertSame($bookingId, $row->held_booking_id, "Slot {$id} must be held by the winning booking.");
        }

        // The single most important assertion in this whole test: the
        // loser's slots that were NOT contested (i.e. not also requested by
        // the winner) must not be left dangling in a held-with-no-booking
        // state. Because reserve() is all-or-nothing, the loser's entire
        // request — including its two uncontested slots — must have been
        // rolled back atomically, leaving them exactly as available as they
        // were before either process ran, with no held_booking_id at all.
        $loserOnlyIds = array_values(array_diff($loserRequested, $winnerRequested));
        self::assertCount(2, $loserOnlyIds, 'Sanity check: the loser must have had exactly two uncontested slots.');

        foreach ($loserOnlyIds as $id) {
            $row = $freshSlots->get($id);
            self::assertNotNull($row, "Slot {$id} must still exist.");
            self::assertSame(
                CourtSlot::STATUS_AVAILABLE,
                $row->status,
                "Slot {$id} was uncontested and requested only by the losing customer — it must remain available, not left dangling as held with no real booking."
            );
            self::assertNull(
                $row->held_booking_id,
                "Slot {$id} must have no held_booking_id — the loser's atomic rollback must clear it, not leave a stale reference."
            );
        }

        // No booking exists at all for the loser (their transaction was
        // rolled back entirely, not partially committed).
        self::assertArrayNotHasKey('booking_id', $resultsByRequest[$loserKey]['result'], 'Loser must have no booking_id — reserve() must not have created any row for the losing attempt.');

        // Exactly one booking total was created across both processes for
        // this court.
        self::assertSame(
            1,
            Booking::query()->where('court_id', $court->getKey())->count(),
            'Exactly one booking must exist for this court after the race — the loser must not have left a partial/orphaned booking row.'
        );
    }

    /**
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $dbConfig = config('database.connections.'.config('database.default'));

        return [
            'APP_ENV' => 'testing',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'BCRYPT_ROUNDS' => '4',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => config('database.default'),
            'DB_HOST' => (string) ($dbConfig['host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($dbConfig['port'] ?? '3306'),
            'DB_DATABASE' => (string) ($dbConfig['database'] ?? 'db_phea_test'),
            'DB_USERNAME' => (string) ($dbConfig['username'] ?? 'root'),
            'DB_PASSWORD' => (string) ($dbConfig['password'] ?? ''),
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
        ];
    }
}
