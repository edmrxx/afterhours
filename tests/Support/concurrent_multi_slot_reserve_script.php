<?php

/**
 * Standalone worker used only by BookingMultiSlotRaceConditionTest.
 *
 * Sibling of concurrent_reserve_script.php, extended to claim SEVERAL
 * court_slot ids in one reserve() call instead of just one. Bootstraps a
 * full, independent copy of the application (its own DB connection/PDO
 * handle, its own PHP process) and calls
 * BookingService::reserve([...ids], ...) against slots the parent test
 * already created and committed. Two of these are launched at once, released
 * by the same barrier file, so the ascending-lock-order discipline inside
 * BookingService::reserve() is exercised by genuine OS-level concurrency on
 * overlapping multi-slot requests, not merely asserted about in isolation.
 *
 * argv: [1]=comma-separated court_slot ids (e.g. "1,2,3")
 *       [2]=customer_name [3]=customer_phone
 *       [4]=barrier_file  [5]=output_file
 *
 * The barrier file lets the parent start both workers, wait until each has
 * finished booting Laravel, and only then release them at (as close to)
 * the same instant as an OS scheduler allows.
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';

/** @var \Illuminate\Contracts\Console\Kernel $kernel */
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

[$_, $slotIdsRaw, $customerName, $customerPhone, $barrierFile, $outputFile] = $argv;

$slotIds = array_values(array_filter(array_map(
    static fn (string $id): int => (int) trim($id),
    explode(',', $slotIdsRaw),
), static fn (int $id): bool => $id > 0));

$readyFile = $outputFile.'.ready';
file_put_contents($readyFile, '1');

// Wait for the parent's go-ahead, capped so a broken test cannot hang CI.
$deadline = microtime(true) + 15;

while (! file_exists($barrierFile)) {
    if (microtime(true) > $deadline) {
        break;
    }
    usleep(500);
}

$result = ['pid' => getmypid(), 'requested_slot_ids' => $slotIds];

try {
    /** @var \App\Services\BookingService $service */
    $service = $app->make(\App\Services\BookingService::class);

    $booking = $service->reserve($slotIds, [
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
    ]);

    $result['success'] = true;
    $result['booking_id'] = $booking->getKey();
    $result['booking_code'] = $booking->code;
    $result['held_slot_ids'] = \App\Models\CourtSlot::query()
        ->where('held_booking_id', $booking->getKey())
        ->orderBy('id')
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();
} catch (\Throwable $e) {
    $result['success'] = false;
    $result['exception'] = get_class($e);
    $result['message'] = $e->getMessage();
}

file_put_contents($outputFile, json_encode($result));
