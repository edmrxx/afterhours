<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SlotGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push the current club-wide rate table onto every court's already-generated
 * schedule, on demand from the console.
 *
 * This is the operator's one-shot "sync everything now" — the same
 * SlotGeneratorService::repriceAll() the settings screen calls when a rate
 * changes, but callable without going through a save. It exists for exactly the
 * case where the rates in Settings are already correct but the slots on the
 * schedule were generated under an older price (e.g. right after a deploy that
 * switched pricing models): saving unchanged settings would detect no change
 * and reprice nothing, so this bypasses that gate and simply applies the
 * current rates.
 *
 * Every guarantee repriceAll() makes holds here — it only ever touches
 * `available` rows, never a held/booked/blocked slot, and never a date before
 * today. Re-running it is safe and idempotent: a slot already at the current
 * rate is left untouched and not counted as changed.
 */
class RepriceAllSlots extends Command
{
    protected $signature = 'slots:reprice-all
                            {--from= : Earliest slot date to touch (YYYY-MM-DD). Defaults to today; never reaches before today.}
                            {--to= : Latest slot date to touch (YYYY-MM-DD). Defaults to no upper bound.}';

    protected $description = "Reprice every court's available future slots to the current club-wide rate table";

    public function handle(SlotGeneratorService $generator): int
    {
        $from = $this->stringOption('from');
        $to = $this->stringOption('to');

        try {
            $summary = $generator->repriceAll($from, $to);
        } catch (Throwable $e) {
            Log::error('slots:reprice-all aborted.', ['error' => $e->getMessage()]);
            $this->error('Reprice aborted: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($summary['courts'] === 0) {
            $this->warn('No courts found — nothing to reprice.');

            return self::SUCCESS;
        }

        $this->table(
            ['Court', 'Slots repriced'],
            array_map(
                static fn (array $court): array => [$court['name'], number_format($court['total'])],
                $summary['per_court'],
            ),
        );

        if ($summary['total'] === 0) {
            $this->info(sprintf(
                'Every available slot on all %d court(s) already matches the current rates — nothing changed.',
                $summary['courts'],
            ));

            return self::SUCCESS;
        }

        $window = $summary['to'] !== null
            ? sprintf('%s to %s', $summary['from'], $summary['to'])
            : sprintf('%s onward', $summary['from']);

        $this->info(sprintf(
            'Repriced %s slot(s) across %d of %d court(s), %s.',
            number_format($summary['total']),
            $summary['courts_changed'],
            $summary['courts'],
            $window,
        ));

        Log::info('slots:reprice-all completed.', [
            'total' => $summary['total'],
            'courts_changed' => $summary['courts_changed'],
            'from' => $summary['from'],
            'to' => $summary['to'],
        ]);

        return self::SUCCESS;
    }

    /**
     * A non-empty option value as a trimmed string, or null when it was absent
     * or blank — the shape repriceAll() expects (null means "no bound"). The
     * service clamps `from` to today and treats an unparsable date as its
     * default, so no format validation is needed here.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
