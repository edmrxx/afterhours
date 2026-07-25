{{--
    Booking detail block, shared by all three booking emails.

    @param array $summary   flattened booking read-model from BookingService
    @param bool  $showCustomer  include the customer block (admin email only)
    @param bool  $showPayment   include the payment reference row
--}}
@php
    $showCustomer ??= false;
    $showPayment ??= false;

    // A booking may span several courts (Booking::slots()). When it does, one
    // "Court" row + one "Time" row would describe only the primary slot and
    // silently drop the rest, so the two collapse into a single per-slot
    // schedule block that names every court beside its own time. A single-court
    // booking never enters that branch and renders exactly as it did before.
    $isMultiCourt = ! empty($summary['is_multi_court']);
    $slots = $summary['slots'] ?? [];

    // Fold each slot's date into its own line only when the booking actually
    // straddles more than one day — otherwise the shared "Date" row still says
    // it once and the per-slot lines stay short.
    $spansDays = count(array_unique(array_filter(array_column($slots, 'date')))) > 1;

    $rows = [];

    $rows[] = ['label' => 'Booking code', 'value' => $summary['code'] ?? '—', 'emphasise' => true];

    if ($isMultiCourt) {
        if (! $spansDays) {
            $rows[] = ['label' => 'Date', 'value' => $summary['date'] ?? '—', 'emphasise' => false];
        }

        // Pre-escape here: the value cell echoes this string raw so the <br>
        // separators survive as real line breaks, which means every court name
        // and time has to be escaped by hand exactly as {{ }} would.
        $lines = array_map(static function (array $slot) use ($spansDays): string {
            $court = e($slot['court_name'] ?? 'Court');
            $time = e($slot['time_range'] ?? '—');

            return $spansDays && ! empty($slot['date'])
                ? $court.' — '.e($slot['date']).', '.$time
                : $court.' — '.$time;
        }, $slots);

        $rows[] = ['label' => 'Courts & times', 'raw' => implode('<br>', $lines), 'emphasise' => false];
    } else {
        $rows[] = ['label' => 'Court', 'value' => $summary['court_name'] ?? '—', 'emphasise' => false];
        $rows[] = ['label' => 'Date', 'value' => $summary['date'] ?? '—', 'emphasise' => false];
        $rows[] = ['label' => 'Time', 'value' => $summary['time_range_full'] ?? '—', 'emphasise' => false];
    }

    if ($showCustomer) {
        $rows[] = ['label' => 'Customer', 'value' => $summary['customer_name'] ?? '—', 'emphasise' => false];
        $rows[] = ['label' => 'Mobile', 'value' => $summary['customer_phone'] ?? '—', 'emphasise' => false];

        if (! empty($summary['customer_email'])) {
            $rows[] = ['label' => 'Email', 'value' => $summary['customer_email'], 'emphasise' => false];
        }

        if (! empty($summary['notes'])) {
            $rows[] = ['label' => 'Notes', 'value' => $summary['notes'], 'emphasise' => false];
        }
    }

    if ($showPayment && ! empty($summary['payment_reference'])) {
        // Name the app the reference actually came from: telling a GoTyme
        // payer we hold their "GCash reference" reads like we logged someone
        // else's payment. Bookings taken before the choice existed carry no
        // method at all, so those stay deliberately neutral.
        $methodLabel = $summary['payment_method_label'] ?? null;

        $rows[] = [
            'label' => $methodLabel ? $methodLabel.' reference' : 'Payment reference',
            'value' => $summary['payment_reference'],
            'emphasise' => true,
        ];
    }
@endphp

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; margin:0 0 28px; border:1px solid #e4e4e6; border-radius:12px; background-color:#f6f6f7;">
    @foreach ($rows as $index => $row)
        <tr>
            <td class="ah-stack" width="40%" style="padding:{{ $index === 0 ? '16px' : '12px' }} 20px 12px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; color:#5a5a5c; {{ $loop->last ? 'padding-bottom:16px;' : 'border-bottom:1px solid #e4e4e6;' }}">
                {{ $row['label'] }}
            </td>
            <td class="ah-stack" width="60%" align="right" style="padding:{{ $index === 0 ? '16px' : '12px' }} 20px 12px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:{{ $row['emphasise'] ? '14px' : '13px' }}; line-height:20px; color:#000000; font-weight:{{ $row['emphasise'] ? '700' : '600' }}; {{ $row['emphasise'] ? 'letter-spacing:0.4px;' : '' }} {{ $loop->last ? 'padding-bottom:16px;' : 'border-bottom:1px solid #e4e4e6;' }}">
                {!! $row['raw'] ?? e($row['value']) !!}
            </td>
        </tr>
    @endforeach
</table>
