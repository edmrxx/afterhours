<?php

/**
 * Standalone worker used only by BookingRaceConditionTest.
 *
 * Bootstraps a full, independent copy of the application (its own DB
 * connection/PDO handle, its own PHP process) and calls
 * BookingService::reserve() against a slot the parent test already created
 * and committed. Two of these are launched at once so the row lock inside
 * BookingService::reserve() is exercised by genuine OS-level concurrency,
 * not merely asserted about in isolation.
 *
 * argv: [1]=court_slot_id [2]=customer_name [3]=customer_phone
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

[$_, $slotId, $customerName, $customerPhone, $barrierFile, $outputFile] = $argv;

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

$result = ['pid' => getmypid()];

try {
    /** @var \App\Models\CourtSlot|null $slot */
    $slot = \App\Models\CourtSlot::query()->find((int) $slotId);

    if (! $slot instanceof \App\Models\CourtSlot) {
        throw new \RuntimeException('Slot not found by worker process.');
    }

    /** @var \App\Services\BookingService $service */
    $service = $app->make(\App\Services\BookingService::class);

    $booking = $service->reserve([$slot->getKey()], [
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
    ]);

    $result['success'] = true;
    $result['booking_id'] = $booking->getKey();
    $result['booking_code'] = $booking->code;
} catch (\Throwable $e) {
    $result['success'] = false;
    $result['exception'] = get_class($e);
    $result['message'] = $e->getMessage();
}

file_put_contents($outputFile, json_encode($result));
