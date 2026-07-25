@extends('emails.layout')

@section('subject', 'Your booking is confirmed')
@section('accent', '#12885e')
@section('preheader')
@if ($summary['is_multi_court'])
Payment verified — {{ implode(', ', $summary['court_names']) }} (booking {{ $summary['code'] }}).
@else
Payment verified — {{ $summary['court_name'] }} on {{ $summary['date'] }}, {{ $summary['time_range_full'] }}.
@endif
@endsection

@section('content')

    {{-- Status badge --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
        <tr>
            <td style="background-color:#e7f6ef; border-radius:999px; padding:7px 15px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; line-height:16px; font-weight:700; color:#0f6f4d; letter-spacing:0.4px; text-transform:uppercase;">
                Confirmed
            </td>
        </tr>
    </table>

    <h1 style="margin:0 0 12px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:24px; line-height:32px; font-weight:700; color:#000000; letter-spacing:-0.4px;">
        You&rsquo;re all set, {{ $summary['customer_first_name'] }}.
    </h1>

    <p style="margin:0 0 26px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:15px; line-height:24px; color:#3d3d3f;">
        We&rsquo;ve verified your payment and locked in your {{ $summary['is_multi_court'] ? 'courts' : 'court' }}. Here are the details &mdash;
        just show your booking code when you arrive.
    </p>

    @include('emails.partials.summary', ['summary' => $summary, 'showPayment' => true])

    {{-- Amount paid --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; margin:0 0 28px; background-color:#e4e4e6; border-radius:12px;">
        <tr>
            <td style="padding:18px 20px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; color:#5a5a5c;">
                Amount paid
            </td>
            <td align="right" class="ah-amount" style="padding:18px 20px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:24px; line-height:28px; font-weight:700; color:#000000; letter-spacing:-0.5px;">
                {{ $summary['amount'] }}
            </td>
        </tr>
    </table>

    @if ($statusUrl)
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
            <tr>
                <td style="background-color:#781714; border-radius:10px;">
                    <a href="{{ $statusUrl }}" target="_blank" rel="noopener"
                       style="display:inline-block; padding:13px 26px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; font-weight:600; color:#ffffff; text-decoration:none;">
                        View my booking
                    </a>
                </td>
            </tr>
        </table>
    @endif

    {{-- Good-to-know --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-top:1px solid #e4e4e6;">
        <tr>
            <td style="padding:22px 0 0;">
                <p style="margin:0 0 10px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; font-weight:700; color:#000000;">
                    Before you play
                </p>
                <p style="margin:0 0 6px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:13px; line-height:21px; color:#5a5a5c;">
                    &bull;&nbsp; Arrive about 10 minutes early so you can start on time.
                </p>
                <p style="margin:0 0 6px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:13px; line-height:21px; color:#5a5a5c;">
                    &bull;&nbsp; Keep booking code <strong style="color:#000000;">{{ $summary['code'] }}</strong> handy at the desk.
                </p>
                <p style="margin:0; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:13px; line-height:21px; color:#5a5a5c;">
                    &bull;&nbsp; Need to change something? Reply to this email or give us a call.
                </p>
            </td>
        </tr>
    </table>

@endsection
