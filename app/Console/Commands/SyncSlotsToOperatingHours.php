<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\SlotGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reshape every court's future schedule to the club's operating hours, on demand
 * from the console — the same SlotGeneratorService::syncToOperatingHours() the
 * "Sync to operating hours" button on the Slots screen calls.
 *
 * Adds the hourly slots for hours that are now open, removes the `available`
 * slots for hours that are now closed, and never touches a held, booked or
 * blocked slot or a date before today. Re-running it is safe and idempotent.
 */
class SyncSlotsToOperatingHours extends Command
{
    protected $signature = 'slots:sync-hours';

    protected $description = "Reshape every court's future available slots to the club's operating hours";

    public function handle(SlotGeneratorService $generator, SettingsService $settings): int
    {
        $opening = (string) ($settings->get(Setting::GROUP_COMPANY, 'operating_opening_time') ?: '07:00');
        $closing = (string) ($settings->get(Setting::GROUP_COMPANY, 'operating_closing_time') ?: '02:00');

        try {
            $summary = $generator->syncToOperatingHours($opening, $closing);
        } catch (Throwable $e) {
            Log::error('slots:sync-hours aborted.', ['error' => $e->getMessage()]);
            $this->error('Sync aborted: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($summary['courts'] === 0) {
            $this->warn('No courts found — nothing to sync.');

            return self::SUCCESS;
        }

        $this->table(
            ['Court', 'Added', 'Removed', 'Kept (history)', 'Blocked in-window', 'Skipped dates'],
            array_map(
                static fn (array $court): array => [
                    $court['name'],
                    number_format($court['added']),
                    number_format($court['removed']),
                    number_format($court['kept_history']),
                    number_format($court['blocked_in_window']),
                    number_format($court['skipped_dates']),
                ],
                $summary['per_court'],
            ),
        );

        if ($summary['blocked_in_window'] > 0) {
            $this->warn(sprintf(
                '%s blocked slot(s) sit within the operating hours — review and unblock any that should sell.',
                number_format($summary['blocked_in_window']),
            ));
        }

        if ($summary['added'] === 0 && $summary['removed'] === 0 && $summary['kept_history'] === 0) {
            $this->info(sprintf(
                'Every court already matches operating hours %s–%s — nothing changed.',
                $opening,
                $closing,
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Synced %d of %d court(s) to %s–%s: added %s, removed %s available slot(s).%s%s',
            $summary['courts_changed'],
            $summary['courts'],
            $opening,
            $closing,
            number_format($summary['added']),
            number_format($summary['removed']),
            $summary['kept_history'] > 0
                ? ' '.number_format($summary['kept_history']).' off-hours slot(s) with booking history were blocked instead of removed.'
                : '',
            $summary['skipped_dates'] > 0
                ? ' '.number_format($summary['skipped_dates']).' date(s) with off-hours bookings were left untouched.'
                : '',
        ));

        Log::info('slots:sync-hours completed.', [
            'opening' => $opening,
            'closing' => $closing,
            'added' => $summary['added'],
            'removed' => $summary['removed'],
            'kept_history' => $summary['kept_history'],
            'courts_changed' => $summary['courts_changed'],
            'skipped_dates' => $summary['skipped_dates'],
        ]);

        return self::SUCCESS;
    }
}
