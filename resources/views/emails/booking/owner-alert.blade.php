@extends('emails.layout')

@php
    // Two moments, one template. `reserved` is the neutral "someone just booked"
    // notice; `confirmed` is the green "their payment cleared" one.
    $isConfirmed = ($event ?? 'reserved') === 'confirmed';
@endphp

@section('subject', $isConfirmed ? 'Booking confirmed' : 'New booking')
@section('accent', $isConfirmed ? '#12885e' : '#b0b1b4')
@section('preheader')
@if ($isConfirmed)
Payment verified for {{ $summary['customer_name'] }} — booking {{ $summary['code'] }} ({{ $summary['amount'] }}).
@else
{{ $summary['customer_name'] }} just booked {{ $summary['is_multi_court'] ? 'multiple courts' : $summary['court_name'] }} — {{ $summary['code'] }}.
@endif
@endsection

@section('content')

    {{-- Status badge --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
        <tr>
            @if ($isConfirmed)
                <td style="background-color:#e7f6ef; border-radius:999px; padding:7px 15px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; line-height:16px; font-weight:700; color:#0f6f4d; letter-spacing:0.4px; text-transform:uppercase;">
                    Confirmed
                </td>
            @else
                <td style="background-color:#e4e4e6; border-radius:999px; padding:7px 15px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; line-height:16px; font-weight:700; color:#48494B; letter-spacing:0.4px; text-transform:uppercase;">
                    New booking
                </td>
            @endif
        </tr>
    </table>

    <h1 style="margin:0 0 12px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:24px; line-height:32px; font-weight:700; color:#000000; letter-spacing:-0.4px;">
        @if ($isConfirmed)
            A booking was just confirmed
        @else
            You have a new booking
        @endif
    </h1>

    <p style="margin:0 0 26px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:15px; line-height:24px; color:#3d3d3f;">
        @if ($isConfirmed)
            <strong style="color:#000000;">{{ $summary['customer_name'] }}</strong>'s payment has been verified and the
            {{ $summary['is_multi_court'] ? 'courts are' : 'court is' }} locked in. Nothing more to do &mdash; this is
            just so you know.
        @else
            <strong style="color:#000000;">{{ $summary['customer_name'] }}</strong> just placed a booking. It's on
            hold while they pay; you'll get another note once the payment is verified. This is just a heads-up &mdash;
            no action needed here.
        @endif
    </p>

    @include('emails.partials.summary', ['summary' => $summary, 'showCustomer' => true, 'showPayment' => $isConfirmed])

    @if ($adminUrl)
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px;">
            <tr>
                <td style="background-color:#781714; border-radius:10px;">
                    <a href="{{ $adminUrl }}" target="_blank" rel="noopener"
                       style="display:inline-block; padding:13px 26px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; font-weight:600; color:#ffffff; text-decoration:none;">
                        View in admin
                    </a>
                </td>
            </tr>
        </table>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-top:1px solid #e4e4e6;">
        <tr>
            <td style="padding:22px 0 0;">
                <p style="margin:0; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:13px; line-height:21px; color:#5a5a5c;">
                    You're getting this because your notification email is set in
                    <strong style="color:#000000;">Settings &rsaquo; Booking</strong>. Clear it there to stop these alerts.
                </p>
            </td>
        </tr>
    </table>

@endsection
