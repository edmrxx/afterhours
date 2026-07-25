@php
    // The HTML twin names the wallet in the reference row label; here it gets
    // its own line so the 13-column label alignment below holds for either
    // name. Pre-choice bookings have no method and simply omit the line.
    $methodLabel = $summary['payment_method_label'] ?? null;
    // Same rule as the HTML summary partial: fold each slot's date into its own
    // line only when the booking straddles more than one day.
    $spansDays = count(array_unique(array_filter(array_column($summary['slots'] ?? [], 'date')))) > 1;
@endphp
{{ $company['name'] }} — BOOKING CONFIRMED
==================================================

You're all set, {{ $summary['customer_first_name'] }}.

We've verified your payment and locked in your {{ $summary['is_multi_court'] ? 'courts' : 'court' }}.
Just show your booking code when you arrive.

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
Amount paid  : {{ $summary['amount'] }}
@if ($summary['payment_reference'])
Reference    : {{ $summary['payment_reference'] }}
@endif
@if ($methodLabel)
Paid with    : {{ $methodLabel }}
@endif

BEFORE YOU PLAY
- Arrive about 10 minutes early so you can start on time.
- Keep booking code {{ $summary['code'] }} handy at the desk.
- Need to change something? Reply to this email or give us a call.
@if ($statusUrl)

View your booking: {{ $statusUrl }}
@endif

--
{{ $company['name'] }}@if ($company['phone']) · {{ $company['phone'] }}@endif
@if ($company['email']){{ $company['email'] }}@endif
