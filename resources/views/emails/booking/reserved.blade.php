@extends('emails.layout')

@section('subject', 'Complete your payment')
@section('accent', '#b45309')
@section('preheader')
@if ($summary['is_multi_court'])
We're holding {{ implode(', ', $summary['court_names']) }} for booking {{ $summary['code'] }} — pay by {{ $summary['hold_expires_at'] }}.
@else
We're holding {{ $summary['court_name'] }} on {{ $summary['date'] }}, {{ $summary['time_range_full'] }} — pay by {{ $summary['hold_expires_at'] }}.
@endif
@endsection

@section('content')

    {{-- Status badge --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
        <tr>
            <td style="background-color:#fdf1e0; border-radius:999px; padding:7px 15px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; line-height:16px; font-weight:700; color:#92400e; letter-spacing:0.4px; text-transform:uppercase;">
                Awaiting payment
            </td>
        </tr>
    </table>

    <h1 style="margin:0 0 12px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:24px; line-height:32px; font-weight:700; color:#000000; letter-spacing:-0.4px;">
        Almost there, {{ $summary['customer_first_name'] }}.
    </h1>

    <p style="margin:0 0 26px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:15px; line-height:24px; color:#3d3d3f;">
        {{-- The payer has not picked a wallet yet at this point in the funnel, so this
             mail must not name one: the booking page is where the QR choice lives. --}}
        We're holding your {{ $summary['is_multi_court'] ? 'courts' : 'court' }} while you pay. Send your payment and upload a screenshot of your receipt
        on your booking page before the hold below runs out — after that, the slot goes back on sale.
    </p>

    {{-- Hold deadline — the one thing this email must not let the reader miss --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; margin:0 0 26px; background-color:#fdf1e0; border-left:3px solid #b45309; border-radius:8px;">
        <tr>
            <td style="padding:16px 18px;">
                <p style="margin:0 0 5px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:11px; line-height:16px; font-weight:700; color:#92400e; text-transform:uppercase; letter-spacing:0.6px;">
                    Pay by
                </p>
                <p style="margin:0; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; font-weight:700; color:#000000;">
                    {{ $summary['hold_expires_at'] }}
                </p>
                <p style="margin:6px 0 0; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; color:#5a5a5c;">
                    That's about {{ $holdMinutes }} minutes from when you reserved.
                </p>
            </td>
        </tr>
    </table>

    @include('emails.partials.summary', ['summary' => $summary])

    {{-- Amount due --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; margin:0 0 28px; background-color:#e4e4e6; border-radius:12px;">
        <tr>
            <td style="padding:18px 20px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; color:#5a5a5c;">
                Amount due
            </td>
            <td align="right" class="ah-amount" style="padding:18px 20px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:24px; line-height:28px; font-weight:700; color:#000000; letter-spacing:-0.5px;">
                {{ $summary['amount'] }}
            </td>
        </tr>
    </table>

    @if ($paymentUrl)
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
            <tr>
                <td style="background-color:#781714; border-radius:10px;">
                    <a href="{{ $paymentUrl }}" target="_blank" rel="noopener"
                       style="display:inline-block; padding:13px 26px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; font-weight:600; color:#ffffff; text-decoration:none;">
                        Pay now
                    </a>
                </td>
            </tr>
        </table>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-top:1px solid #e4e4e6;">
        <tr>
            <td style="padding:22px 0 0;">
                <p style="margin:0; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:13px; line-height:21px; color:#5a5a5c;">
                    Changed your mind? You can cancel the hold from your booking page — reply to this
                    email or give us a call if you need help.
                </p>
            </td>
        </tr>
    </table>

@endsection
