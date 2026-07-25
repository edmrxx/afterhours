@extends('emails.layout')

@php
    // This is the one booking mail that SHOULD name the wallet: its whole job
    // is sending a human to the right ledger, and there are now two of them.
    // Older bookings predate the choice and have no method recorded — say
    // nothing rather than guess GCash and send staff to the wrong app.
    $methodLabel = $summary['payment_method_label'] ?? null;
@endphp

@section('subject', 'Payment to verify')
@section('accent', '#c97a08')
@section('preheader')
{{ $summary['customer_name'] }} submitted {{ $methodLabel ? $methodLabel.' reference' : 'payment reference' }} {{ $summary['payment_reference'] ?: '—' }} for {{ $summary['amount'] }}.
@endsection

@section('content')

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
        <tr>
            <td style="background-color:#fdf3e2; border-radius:999px; padding:7px 15px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; line-height:16px; font-weight:700; color:#96590a; letter-spacing:0.4px; text-transform:uppercase;">
                Awaiting verification
            </td>
        </tr>
    </table>

    <h1 style="margin:0 0 12px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:24px; line-height:32px; font-weight:700; color:#000000; letter-spacing:-0.4px;">
        New payment to verify
    </h1>

    <p style="margin:0 0 26px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:15px; line-height:24px; color:#3d3d3f;">
        {{-- Blade only recognises a directive after whitespace, so the no-method branch
             leaves a double space; HTML collapses it the same way it collapses the
             indentation everywhere else in this file. --}}
        A customer submitted a @if ($methodLabel)<strong style="color:#000000;">{{ $methodLabel }}</strong> @endif payment reference. Match it against
        that account, then confirm or reject the booking. The slot stays on hold until you do
        @if ($summary['hold_expires_at']) &mdash; until <strong style="color:#000000;">{{ $summary['hold_expires_at'] }}</strong>@endif.
    </p>

    {{-- The number to check, front and centre. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; margin:0 0 26px; background-color:#e4e4e6; border-radius:12px;">
        <tr>
            <td style="padding:20px;">
                <p style="margin:0 0 6px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:11px; line-height:16px; font-weight:700; color:#5a5a5c; text-transform:uppercase; letter-spacing:0.7px;">
                    {{ $methodLabel ? $methodLabel.' reference' : 'Payment reference' }}
                </p>
                <p style="margin:0; font-family:'Courier New',Courier,monospace; font-size:22px; line-height:28px; font-weight:700; color:#000000; letter-spacing:1px;">
                    {{ $summary['payment_reference'] ?: 'Not provided' }}
                </p>
            </td>
            <td align="right" style="padding:20px;">
                <p style="margin:0 0 6px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:11px; line-height:16px; font-weight:700; color:#5a5a5c; text-transform:uppercase; letter-spacing:0.7px;">
                    Amount
                </p>
                <p class="ah-amount" style="margin:0; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:24px; line-height:28px; font-weight:700; color:#000000; letter-spacing:-0.5px;">
                    {{ $summary['amount'] }}
                </p>
            </td>
        </tr>
    </table>

    @include('emails.partials.summary', ['summary' => $summary, 'showCustomer' => true])

    @if ($verifyUrl)
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px;">
            <tr>
                <td style="background-color:#781714; border-radius:10px;">
                    <a href="{{ $verifyUrl }}" target="_blank" rel="noopener"
                       style="display:inline-block; padding:13px 26px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; font-weight:600; color:#ffffff; text-decoration:none;">
                        Open in admin
                    </a>
                </td>
            </tr>
        </table>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-top:1px solid #e4e4e6;">
        <tr>
            <td style="padding:22px 0 0;">
                <p style="margin:0; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:13px; line-height:21px; color:#5a5a5c;">
                    If the hold lapses before anyone acts, the booking is marked expired automatically and the
                    slot goes back on sale.
                </p>
            </td>
        </tr>
    </table>

@endsection
