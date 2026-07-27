@extends('emails.layout')

@section('subject', 'About your booking')
@section('accent', '#d33a32')
@section('preheader')
We couldn&rsquo;t verify the payment for booking {{ $summary['code'] }}.
@endsection

@section('content')

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
        <tr>
            <td style="background-color:#fdeceb; border-radius:999px; padding:7px 15px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; line-height:16px; font-weight:700; color:#a82f28; letter-spacing:0.4px; text-transform:uppercase;">
                Not verified
            </td>
        </tr>
    </table>

    <h1 style="margin:0 0 12px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:24px; line-height:32px; font-weight:700; color:#000000; letter-spacing:-0.4px;">
        We couldn&rsquo;t confirm this one, {{ $summary['customer_first_name'] }}.
    </h1>

    <p style="margin:0 0 24px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:15px; line-height:24px; color:#3d3d3f;">
        We reviewed the payment for booking <strong style="color:#000000;">{{ $summary['code'] }}</strong>
        and weren&rsquo;t able to match it, so the slot has been released. Nothing has been charged by us
        &mdash; if money did leave your account, send us the receipt and we&rsquo;ll sort it out.
    </p>

    @if ($reason)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; margin:0 0 26px; background-color:#fdeceb; border-left:3px solid #d33a32; border-radius:8px;">
            <tr>
                <td style="padding:16px 18px;">
                    <p style="margin:0 0 5px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:11px; line-height:16px; font-weight:700; color:#a82f28; text-transform:uppercase; letter-spacing:0.6px;">
                        Reason
                    </p>
                    <p style="margin:0; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:14px; line-height:22px; color:#3d3d3f;">
                        {{ $reason }}
                    </p>
                </td>
            </tr>
        </table>
    @endif

    @include('emails.partials.summary', ['summary' => $summary, 'showPayment' => true])

    @if ($rebookUrl)
        {{-- Noir, like every other CUSTOMER-facing CTA (reserved, confirmed).
             The two staff emails keep the crimson button on purpose: it is the
             one visual cue that separates an internal tool link from the
             actions a customer is asked to take. --}}
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px;">
            <tr>
                <td style="background-color:#000000; border-radius:10px;">
                    <a href="{{ $rebookUrl }}" target="_blank" rel="noopener"
                       style="display:inline-block; padding:13px 26px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; font-weight:600; color:#ffffff; text-decoration:none;">
                        Book another slot
                    </a>
                </td>
            </tr>
        </table>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-top:1px solid #e4e4e6;">
        <tr>
            <td style="padding:22px 0 0;">
                <p style="margin:0; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:13px; line-height:21px; color:#5a5a5c;">
                    Think this was a mistake? Reply to this email with your payment receipt or reference number
                    and we&rsquo;ll take another look right away.
                </p>
            </td>
        </tr>
    </table>

@endsection
