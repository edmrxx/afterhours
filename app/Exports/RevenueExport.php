<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\ReportService;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the revenue report as CSV: one row per period per court, followed by
 * a grand total.
 *
 * The body comes off an unbuffered driver cursor rather than a materialised
 * result set, so the writer's memory profile is flat no matter how wide the
 * date range or how many courts the club runs.
 *
 * Like BookingsExport, the file opens with a UTF-8 BOM so Excel renders
 * Philippine names and peso figures correctly, and money is written as a bare
 * decimal so the cell is numeric rather than text.
 */
final class RevenueExport
{
    private const BOM = "\xEF\xBB\xBF";

    /** @var list<string> */
    private const COLUMNS = [
        'Period Start',
        'Period',
        'Court',
        'Bookings',
        'Revenue (PHP)',
        'Average Booking (PHP)',
    ];

    public function __construct(private readonly ReportService $reports) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filename(array $filters): string
    {
        return sprintf(
            'phea-revenue-report_%s_%s_to_%s.csv',
            (string) ($filters['granularity'] ?? 'day'),
            (string) $filters['date_from'],
            (string) $filters['date_to'],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function response(array $filters): StreamedResponse
    {
        $filename = $this->filename($filters);

        return new StreamedResponse(
            function () use ($filters): void {
                $handle = fopen('php://output', 'wb');

                if ($handle === false) {
                    return;
                }

                fwrite($handle, self::BOM);

                $this->writeRow($handle, self::COLUMNS);

                $totalBookings = 0;
                $totalRevenue = 0.0;
                $written = 0;

                foreach ($this->reports->revenueBreakdownCursor($filters) as $row) {
                    $bookings = (int) $row->bookings;
                    $revenue = (float) $row->revenue;

                    $totalBookings += $bookings;
                    $totalRevenue += $revenue;

                    $this->writeRow($handle, [
                        (string) $row->period,
                        $this->reports->labelForPeriod($row->period, $filters),
                        (string) $row->court_name,
                        (string) $bookings,
                        $this->money($revenue),
                        $this->money($bookings > 0 ? $revenue / $bookings : 0.0),
                    ]);

                    if (++$written % 250 === 0) {
                        flush();
                    }
                }

                // A totals line is what the finance team reaches for first, and
                // computing it from the same stream guarantees it reconciles
                // with the rows above it.
                $this->writeRow($handle, [
                    '',
                    'TOTAL',
                    'All courts',
                    (string) $totalBookings,
                    $this->money($totalRevenue),
                    $this->money($totalBookings > 0 ? $totalRevenue / $totalBookings : 0.0),
                ]);

                fclose($handle);
            },
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $filename,
                ),
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /* --------------------------------------------------------------------- */
    /* Writing                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * @param  resource  $handle
     * @param  list<string>  $row
     */
    private function writeRow($handle, array $row): void
    {
        // Explicit empty escape: required on PHP 8.4, and correct per RFC 4180.
        fputcsv($handle, array_map($this->neutralise(...), $row), ',', '"', '');
    }

    /** Stop a court name beginning with `=` from becoming a live formula. */
    private function neutralise(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        if ($value !== '' && str_contains('=+-@', $value[0])) {
            return "'".$value;
        }

        return $value;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
