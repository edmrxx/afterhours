<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Booking;
use App\Models\CourtSlot;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the bookings report as CSV.
 *
 * No spreadsheet library is involved and none is wanted: a CSV writer that
 * yields rows straight to `php://output` holds one booking in memory at a time,
 * so a 200-row export and a 200,000-row export cost the same. A library that
 * builds a workbook object first would fall over on the second.
 *
 * Two details that make the difference between a file Excel opens correctly and
 * one it mangles:
 *
 *  - a UTF-8 BOM, without which Excel on Windows reads the file as the legacy
 *    codepage and turns "Peña" into "PeÃ±a";
 *  - amounts written as bare numbers (no ₱, no thousands separator) so they
 *    land in the cell as numbers rather than text.
 */
final class BookingsExport
{
    /** Bytes that tell Excel the file is UTF-8. */
    private const BOM = "\xEF\xBB\xBF";

    /** @var list<string> */
    private const COLUMNS = [
        'Booking Code',
        'Booked On',
        'Court',
        'Court Code',
        'Play Date',
        'Start Time',
        'End Time',
        'Additional Slots',
        'Customer',
        'Phone',
        'Email',
        'Amount (PHP)',
        'Status',
        'Payment Reference',
        'Payment Submitted',
        'Confirmed At',
        'Confirmed By',
        'Rejected At',
        'Rejected By',
        'Rejection Reason',
        'Cancelled At',
        'Notes',
    ];

    public function __construct(private readonly ReportService $reports) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filename(array $filters): string
    {
        return sprintf(
            'afterhours-bookings-report_%s_to_%s.csv',
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

                $written = 0;

                foreach ($this->reports->bookingsCursor($filters) as $booking) {
                    $this->writeRow($handle, $this->row($booking));

                    // Push to the client periodically: the browser starts the
                    // download immediately instead of waiting on the full set,
                    // and PHP's output buffer never grows without bound.
                    if (++$written % 250 === 0) {
                        flush();
                    }
                }

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
                // Stops nginx buffering the whole stream before sending it on.
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function row(Booking $booking): array
    {
        $slot = $booking->relationLoaded('slot') ? $booking->slot : null;

        return [
            (string) $booking->code,
            $this->dateTime($booking->created_at),
            (string) ($booking->court?->name ?? '—'),
            (string) ($booking->court?->code ?? '—'),
            $slot !== null ? $this->date($slot->slot_date) : '—',
            $slot !== null ? $this->time($slot->getRawOriginal('start_time')) : '—',
            $slot !== null ? $this->time($slot->getRawOriginal('end_time')) : '—',
            $this->additionalSlots($booking, $slot),
            (string) $booking->customer_name,
            (string) $booking->customer_phone,
            (string) ($booking->customer_email ?? ''),
            $this->money($booking->amount),
            (string) $booking->status,
            (string) ($booking->payment_reference ?? ''),
            $this->dateTime($booking->payment_submitted_at),
            $this->dateTime($booking->confirmed_at),
            (string) ($booking->confirmedBy?->name ?? ''),
            $this->dateTime($booking->rejected_at),
            (string) ($booking->rejectedBy?->name ?? ''),
            (string) ($booking->rejection_reason ?? ''),
            $this->dateTime($booking->cancelled_at),
            (string) ($booking->notes ?? ''),
        ];
    }

    /**
     * Every slot in the booking's full `slots` relation other than the primary
     * one already shown in the Play Date / Start Time / End Time columns,
     * rendered as a semicolon-joined list of time ranges (e.g.
     * "6:00 PM - 7:00 PM; 8:00 PM - 9:00 PM"). Blank for a single-slot
     * booking. This is purely a display concern reading an eager-loaded
     * Eloquent relation — it never feeds a total, so it carries none of the
     * overcounting risk that a held_booking_id *join* would in an aggregate.
     */
    private function additionalSlots(Booking $booking, ?CourtSlot $primary): string
    {
        if (! $booking->relationLoaded('slots')) {
            return '';
        }

        $primaryId = $primary?->getKey();

        return $booking->slots
            ->reject(fn (CourtSlot $slot): bool => $slot->getKey() === $primaryId)
            ->map(fn (CourtSlot $slot): string => $slot->startsAt()->format('g:i A').' - '.$slot->endsAt()->format('g:i A'))
            ->implode('; ');
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
        // The empty escape argument is deliberate and required: PHP 8.4
        // deprecates relying on the default backslash escape, and RFC 4180
        // wants doubled quotes rather than backslashes anyway.
        fputcsv($handle, array_map($this->neutralise(...), $row), ',', '"', '');
    }

    /**
     * Defuse spreadsheet formula injection.
     *
     * A customer whose "name" is `=HYPERLINK("http://evil","Click")` becomes a
     * live formula the moment an admin opens the export. Prefixing with a
     * single quote makes Excel and LibreOffice treat the cell as text.
     */
    private function neutralise(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        if ($value !== '' && str_contains('=+-@', $value[0])) {
            return "'".$value;
        }

        return $value;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function dateTime(mixed $value): string
    {
        return $value instanceof Carbon ? $value->format('Y-m-d H:i:s') : '';
    }

    private function date(mixed $value): string
    {
        return $value instanceof Carbon ? $value->format('Y-m-d') : (string) $value;
    }

    /**
     * `start_time` / `end_time` are intentionally left uncast on CourtSlot, so
     * they arrive as raw H:i:s strings.
     */
    private function time(mixed $value): string
    {
        $value = (string) $value;

        return $value === '' ? '' : substr($value, 0, 5);
    }
}
