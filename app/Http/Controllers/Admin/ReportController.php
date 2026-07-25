<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exports\BookingsExport;
use App\Exports\RevenueExport;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\AuditTrailService;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The two reporting screens and their CSV exports.
 *
 * The controller is deliberately thin: every figure comes from ReportService,
 * which computes it with a SQL aggregate. Nothing here iterates a result set,
 * and nothing here builds a query.
 *
 * Filters arrive on the query string and are normalised — not merely validated
 * — by `ReportService::normaliseFilters()`. That guarantees a bounded window
 * and a whitelisted granularity/basis regardless of what a hand-edited URL
 * asks for, which is what lets the service interpolate the period expression
 * into SQL safely.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly AuditTrailService $audit,
    ) {}

    public function bookings(Request $request): Response
    {
        $filters = $this->reports->normaliseFilters($request->all());

        $detail = $this->reports->bookingsDetail($filters, [
            'search' => $request->query('search'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => (int) $request->query('per_page', '25'),
        ]);

        return Inertia::render('Admin/Reports/Bookings', [
            'summary' => $this->reports->bookingsSummary($filters),
            'byStatus' => $this->reports->bookingsByStatus($filters),
            'byPeriod' => $this->reports->bookingsByPeriod($filters),
            'byCourt' => $this->reports->bookingsByCourt($filters),
            'bookings' => $detail->through(fn (Booking $booking): array => $this->bookingRow($booking)),
            'filters' => $this->filterProps($request, $filters),
            'options' => $this->options(),
            'rangeLabel' => $this->reports->rangeLabel($filters),
        ]);
    }

    public function revenue(Request $request): Response
    {
        $filters = $this->reports->normaliseFilters($request->all());

        $byPeriod = $this->reports->revenueByPeriod($filters);

        return Inertia::render('Admin/Reports/Revenue', [
            // The period series is passed back in so the peak can be derived
            // from data already fetched rather than from a second query.
            'summary' => $this->reports->revenueSummary($filters, $byPeriod),
            'byPeriod' => $byPeriod,
            'byCourt' => $this->reports->revenueByCourt($filters),
            'filters' => $this->filterProps($request, $filters),
            'options' => $this->options(),
            'rangeLabel' => $this->reports->rangeLabel($filters),
        ]);
    }

    /**
     * Stream either report as CSV.
     *
     * The response is a StreamedResponse, so the audit entry has to be written
     * here — the moment the callback runs, headers are already flushed and a
     * failed write could no longer be reported to anyone.
     */
    public function export(Request $request, string $type): StreamedResponse
    {
        abort_unless(in_array($type, ['bookings', 'revenue'], true), 404);

        $filters = $this->reports->normaliseFilters($request->all());

        $export = $type === 'bookings'
            ? app(BookingsExport::class)
            : app(RevenueExport::class);

        $this->audit->log(
            module: 'Reports',
            action: 'view',
            description: sprintf(
                'Exported the %s report as CSV (%s%s%s).',
                $type,
                $this->reports->rangeLabel($filters),
                $filters['court_id'] !== null ? ', court #'.$filters['court_id'] : '',
                $filters['status'] !== null ? ', status '.Str::headline($filters['status']) : '',
            ),
        );

        return $export->response($filters);
    }

    /* --------------------------------------------------------------------- */
    /* Props                                                                  */
    /* --------------------------------------------------------------------- */

    /**
     * Filter state echoed back to the page. The normalised values are returned
     * (not the raw input) so the controls always show the window that was
     * actually queried, even after clamping.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function filterProps(Request $request, array $filters): array
    {
        return [
            ...$filters,
            'search' => (string) $request->query('search', ''),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction') === 'asc' ? 'asc' : 'desc',
            'per_page' => max(10, min(100, (int) $request->query('per_page', '25'))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'courts' => $this->reports->courtOptions(),
            'statuses' => array_map(
                static fn (string $status): array => [
                    'value' => $status,
                    'label' => Str::headline($status),
                ],
                Booking::STATUSES,
            ),
            'granularities' => [
                ['value' => 'day', 'label' => 'Daily'],
                ['value' => 'week', 'label' => 'Weekly'],
                ['value' => 'month', 'label' => 'Monthly'],
            ],
            'bases' => [
                ['value' => ReportService::BASIS_BOOKED, 'label' => 'Date booked'],
                ['value' => ReportService::BASIS_PLAYED, 'label' => 'Date played'],
            ],
        ];
    }

    /**
     * One row of the bookings detail table, projected down to what the grid
     * renders so the paginator payload stays small.
     *
     * @return array<string, mixed>
     */
    private function bookingRow(Booking $booking): array
    {
        $slot = $booking->relationLoaded('slot') ? $booking->slot : null;

        return [
            'id' => (int) $booking->getKey(),
            'code' => $booking->code,
            'court' => $booking->court?->name ?? '—',
            'customer_name' => $booking->customer_name,
            'customer_phone' => $booking->customer_phone,
            'amount' => (float) $booking->amount,
            'status' => $booking->status,
            'slot_label' => $this->reports->slotLabel($slot),
            'created_at_label' => $booking->created_at?->format('j M Y, g:i A'),
            'url' => route('admin.bookings.show', $booking->code),
        ];
    }
}
