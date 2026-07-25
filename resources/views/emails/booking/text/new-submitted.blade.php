@php
    // Text twin of emails/booking/new-submitted.blade.php — same job, same
    // facts. The wallet gets its own aligned line here rather than riding on
    // the reference label, so the column layout below survives either name.
    //
    // No method recorded prints an em dash, never a guessed "GCash": a
    // booking taken before the choice existed genuinely has no answer, and
    // naming the wrong app sends the verifier to the wrong ledger.
    $methodLabel = $summary['payment_method_label'] ?? null;
    // Same rule as the HTML summary partial: fold each slot's date into its own
    // line only when the booking straddles more than one day.
    $spansDays = count(array_unique(array_filter(array_column($summary['slots'] ?? [], 'date')))) > 1;
@endphp
{{ $company['name'] }} — NEW PAYMENT TO VERIFY
==================================================

A customer submitted a {{ $methodLabel ? $methodLabel.' ' : '' }}payment reference. Match it against that
account, then confirm or reject the booking.
@if ($summary['hold_expires_at'])
The slot stays on hold until {{ $summary['hold_expires_at'] }}.
@endif

REFERENCE : {{ $summary['payment_reference'] ?: 'Not provided' }}
PAID WITH : {{ $methodLabel ?: '—' }}
AMOUNT    : {{ $summary['amount'] }}

Booking code : {{ $summary['code'] }}
@if ($summary['is_multi_court'])
@unless ($spansDays)
Date         : {{ $summary['date'] }}
@endunless
Courts & times:
@foreach ($summary['slots'] as $slot)
  {{ $slot['court_name'] }} — @if ($spansDays){{ $slot['date'] }}, @endif{{ $slot['time_range'] }}
@endforeach
@else
Court        : {{ $summary['court_name'] }}
Date         : {{ $summary['date'] }}
Time         : {{ $summary['time_range_full'] }}
@endif

CUSTOMER
Name   : {{ $summary['customer_name'] }}
Mobile : {{ $summary['customer_phone'] }}
@if ($summary['customer_email'])
Email  : {{ $summary['customer_email'] }}
@endif
@if ($summary['notes'])
Notes  : {{ $summary['notes'] }}
@endif
@if ($verifyUrl)

Open in admin: {{ $verifyUrl }}
@endif

If the hold lapses before anyone acts, the booking is marked expired
automatically and the slot goes back on sale.

--
{{ $company['name'] }}
