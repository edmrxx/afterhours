@php
    // Same rule as the HTML summary partial: fold each slot's date into its own
    // line only when the booking straddles more than one day.
    $spansDays = count(array_unique(array_filter(array_column($summary['slots'] ?? [], 'date')))) > 1;
@endphp
{{ $company['name'] }} — AWAITING PAYMENT
==================================================

Almost there, {{ $summary['customer_first_name'] }}.

We're holding your {{ $summary['is_multi_court'] ? 'courts' : 'court' }} while you pay. Send your payment and upload a
screenshot of your receipt on your booking page before the hold below runs
out — after that, the slot goes back on sale.

Pay by     : {{ $summary['hold_expires_at'] }} (about {{ $holdMinutes }} minutes from when you reserved)

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
Amount due   : {{ $summary['amount'] }}
@if ($paymentUrl)

Pay now: {{ $paymentUrl }}
@endif

Changed your mind? You can cancel the hold from your booking page — reply
to this email or give us a call if you need help.

--
{{ $company['name'] }}@if ($company['phone']) · {{ $company['phone'] }}@endif
@if ($company['email']){{ $company['email'] }}@endif
