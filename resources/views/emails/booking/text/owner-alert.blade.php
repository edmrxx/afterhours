@php
    // Text twin of emails/booking/owner-alert.blade.php — same facts, same two
    // modes. Reminder only: no call to action, just who booked what.
    $isConfirmed = ($event ?? 'reserved') === 'confirmed';
    $spansDays = count(array_unique(array_filter(array_column($summary['slots'] ?? [], 'date')))) > 1;
@endphp
{{ $company['name'] }} — {{ $isConfirmed ? 'BOOKING CONFIRMED' : 'NEW BOOKING' }}
==================================================

@if ($isConfirmed)
{{ $summary['customer_name'] }}'s payment has been verified and the booking is
locked in. Nothing to do — this is just so you know.
@else
{{ $summary['customer_name'] }} just placed a booking. It's on hold while they
pay; you'll get another note once the payment is verified. Just a heads-up —
no action needed.
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

CUSTOMER
Name   : {{ $summary['customer_name'] }}
Mobile : {{ $summary['customer_phone'] }}
@if ($summary['customer_email'])
Email  : {{ $summary['customer_email'] }}
@endif
@if ($isConfirmed && $summary['payment_reference'])
Payment ref : {{ $summary['payment_reference'] }}
@endif
@if ($adminUrl)

View in admin: {{ $adminUrl }}
@endif

You're getting this because your notification email is set in
Settings > Booking. Clear it there to stop these alerts.

--
{{ $company['name'] }}
