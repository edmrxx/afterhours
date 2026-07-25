<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The crown jewel: two customers must never both win the same court_slot.
 *
 * The first test spins up two genuinely independent PHP processes — each
 * with its own PDO connection — so the row lock inside
 * BookingService::reserve() is exercised by real concurrency rather than by
 * asserting the code "looks right" in isolation. This is deliberately NOT
 * wrapped in the usual per-test database transaction: the child processes
 * need to see committed rows through their own connections, and the parent
 * needs to read what they committed.
 */
class BookingRaceConditionTest extends TestCase
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

    public function test_two_concurrent_reserves_on_the_same_slot_exactly_one_wins(): void
    {
        $court = Court::factory()->create();
        $this->createdCourtIds[] = $court->getKey();

        $slot = CourtSlot::factory()->forCourt($court)->onDate(Carbon::today()->addDay())->available()->create();

        $dir = sys_get_temp_dir();
        $barrier = $dir.'/phea_race_barrier_'.uniqid('', true);
        $outA = $dir.'/phea_race_a_'.uniqid('', true).'.json';
        $outB = $dir.'/phea_race_b_'.uniqid('', true).'.json';
        $this->scratchFiles = [$outA, $outB, $barrier];

        $script = __DIR__.'/../../Support/concurrent_reserve_script.php';
        $php = (new \Symfony\Component\Process\PhpExecutableFinder)->find() ?: PHP_BINARY;

        $env = $this->childEnvironment();

        $procA = new Process([$php, $script, (string) $slot->getKey(), 'Racer One', '09171234561', $barrier, $outA], null, $env);
        $procB = new Process([$php, $script, (string) $slot->getKey(), 'Racer Two', '09171234562', $barrier, $outB], null, $env);

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

        self::assertSame(0, $procA->getExitCode(), 'Worker A crashed: '.$procA->getErrorOutput());
        self::assertSame(0, $procB->getExitCode(), 'Worker B crashed: '.$procB->getErrorOutput());

        self::assertFileExists($outA, 'Worker A produced no output. Stderr: '.$procA->getErrorOutput());
        self::assertFileExists($outB, 'Worker B produced no output. Stderr: '.$procB->getErrorOutput());

        $resultA = json_decode((string) file_get_contents($outA), true);
        $resultB = json_decode((string) file_get_contents($outB), true);

        $results = [$resultA, $resultB];
        $successes = array_values(array_filter($results, fn (array $r): bool => $r['success'] === true));
        $failures = array_values(array_filter($results, fn (array $r): bool => $r['success'] === false));

        self::assertCount(1, $successes, 'Exactly one of the two concurrent reserve() calls must win. Got: '.json_encode($results));
        self::assertCount(1, $failures, 'Exactly one of the two concurrent reserve() calls must lose. Got: '.json_encode($results));

        self::assertSame(BookingException::class, $failures[0]['exception']);

        // Exactly one booking exists for this slot, and the slot is held by it.
        $bookingsForSlot = Booking::query()->where('court_slot_id', $slot->getKey())->get();
        self::assertCount(1, $bookingsForSlot);
        self::assertSame($successes[0]['booking_id'], $bookingsForSlot->first()->getKey());
        self::assertSame(Booking::STATUS_AWAITING_PAYMENT, $bookingsForSlot->first()->status);

        $slot->refresh();
        self::assertSame(CourtSlot::STATUS_HELD, $slot->status);
        self::assertSame($successes[0]['booking_id'], $slot->held_booking_id);
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

    /* ===================================================================== */
    /* Guard clauses reserve() must enforce even outside a genuine race       */
    /* ===================================================================== */

    public function test_reserve_refuses_a_slot_that_is_already_held(): void
    {
        $slot = $this->makeSlot(['status' => CourtSlot::STATUS_HELD]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('just taken');

        app(BookingService::class)->reserve([$slot->getKey()], $this->customer());
    }

    public function test_reserve_refuses_a_slot_that_is_already_booked(): void
    {
        $slot = $this->makeSlot(['status' => CourtSlot::STATUS_BOOKED]);

        $this->expectException(BookingException::class);

        app(BookingService::class)->reserve([$slot->getKey()], $this->customer());
    }

    public function test_reserve_refuses_a_blocked_slot(): void
    {
        $slot = $this->makeSlot(['status' => CourtSlot::STATUS_BLOCKED]);

        try {
            app(BookingService::class)->reserve([$slot->getKey()], $this->customer());
            self::fail('Expected a BookingException for a blocked slot.');
        } catch (BookingException $e) {
            self::assertStringContainsString('not open for booking', $e->getMessage());
        }
    }

    public function test_reserve_refuses_a_slot_in_the_past(): void
    {
        $slot = $this->makeSlot([
            'slot_date' => Carbon::yesterday()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => CourtSlot::STATUS_AVAILABLE,
        ]);

        try {
            app(BookingService::class)->reserve([$slot->getKey()], $this->customer());
            self::fail('Expected a BookingException for a past slot.');
        } catch (BookingException $e) {
            self::assertStringContainsString('already passed', $e->getMessage());
        }
    }

    public function test_reserve_leaves_the_slot_untouched_after_a_refusal(): void
    {
        $slot = $this->makeSlot(['status' => CourtSlot::STATUS_BOOKED]);
        $originalStatus = $slot->status;

        try {
            app(BookingService::class)->reserve([$slot->getKey()], $this->customer());
        } catch (BookingException) {
            // expected
        }

        $slot->refresh();
        self::assertSame($originalStatus, $slot->status);
        self::assertSame(0, Booking::query()->where('court_slot_id', $slot->getKey())->count());
    }

    /**
     * Creates a court + slot and registers the court for teardown cleanup —
     * every test in this transaction-less class must self-clean.
     *
     * @param  array<string, mixed>  $slotState
     */
    private function makeSlot(array $slotState = []): CourtSlot
    {
        $court = Court::factory()->create();
        $this->createdCourtIds[] = $court->getKey();

        return CourtSlot::factory()
            ->forCourt($court)
            ->onDate(Carbon::today()->addDay())
            ->atHour(9)
            ->state($slotState)
            ->create();
    }

    /**
     * @return array<string, string>
     */
    private function customer(): array
    {
        return [
            'customer_name' => 'Test Customer',
            'customer_phone' => '09171234567',
        ];
    }
}
