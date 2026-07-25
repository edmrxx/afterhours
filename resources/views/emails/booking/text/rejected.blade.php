@php
    // The HTML twin names the wallet in the reference row label; here it gets
    // its own line so the 13-column label alignment below holds for either
    // name. Pre-choice bookings have no method and simply omit the line.
    $methodLabel = $summary['payment_method_label'] ?? null;
    // Same rule as the HTML summary partial: fold each slot's date into its own
    // line only when the booking straddles more than one day.
    $spansDays = count(array_unique(array_filter(array_column($summary['slots'] ?? [], 'date')))) > 1;
@endphp
{{ $company['name'] }} — PAYMENT NOT VERIFIED
==================================================

We couldn't confirm this one, {{ $summary['customer_first_name'] }}.

We reviewed the payment for booking {{ $summary['code'] }} and weren't able to
match it, so the slot has been released. Nothing has been charged by us — if
money did leave your account, send us the receipt and we'll sort it out.
@if ($reason)

REASON
{{ $reason }}
@endif

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
Amount       : {{ $summary['amount'] }}
@if ($summary['payment_reference'])
Reference    : {{ $summary['payment_reference'] }}
@endif
@if ($methodLabel)
Paid with    : {{ $methodLabel }}
@endif

Think this was a mistake? Reply to this email with your payment receipt or
reference number and we'll take another look right away.
@if ($rebookUrl)

Book another slot: {{ $rebookUrl }}
@endif

--
{{ $company['name'] }}@if ($company['phone']) · {{ $company['phone'] }}@endif
@if ($company['email']){{ $company['email'] }}@endif
