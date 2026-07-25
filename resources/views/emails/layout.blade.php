{{--
    The After Hours transactional email shell.

    Hand-written, table-based and inline-styled on purpose: Outlook strips
    <style> blocks, Gmail clips large documents, and none of them run Tailwind.
    Colours are the client's brand palette written as literal hex, because
    email needs literal hex — this is the one place hardcoding is correct:

        Crimson     #781714    primary buttons and CTAs (white text, 11.0:1)
        Noir        #000000    header band and dark surfaces (white text, 21:1)
        Graphite    #48494B    links, secondary accents (white text, 9.0:1)
        Ash         #b0b1b4    kickers and rules (never carries white text)
        Bone 300    #e4e4e6    hairline borders
        Bone 100    #f6f6f7    page and footer background

    The header is a noir background COLOUR, not an image, so when a client
    blocks images the header still reads as a branded black band with the
    logo's alt text in it.
--}}
@php
    $brandName = $company['name'] ?? config('app.name');
    $isUploadedLogo = ! empty($company['logo_is_uploaded']);
    // The stock fallback is the light wordmark, because the brand bar below is
    // a noir band; the crimson logo-mark.png is the one drawn for light
    // surfaces. An uploaded logo keeps its own file either way.
    $logoFile = $company['logo_path'] ?? public_path('images/brand/logo-mark-light.png');
    // Embed the logo inline (CID) so it renders from any inbox. A linked image
    // would point at APP_URL, which is routinely a local/private address the
    // recipient cannot reach. `$message` is absent during Mailable::render()
    // (previews and tests), so fall back to an absolute URL there.
    $logoSrc = (isset($message) && is_file($logoFile))
        ? $message->embed($logoFile)
        : ($company['logo_url'] ?? url('/images/brand/logo-mark-light.png'));
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="color-scheme" content="light" />
    <meta name="supported-color-schemes" content="light" />
    <title>@yield('subject', $brandName)</title>
    <style type="text/css">
        /* Progressive enhancement only — every rule below has an inline twin. */
        body { margin:0; padding:0; width:100% !important; }
        img { border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic; }
        table { border-collapse:collapse !important; }
        a { color:#48494B; }
        @media only screen and (max-width:600px) {
            .ah-shell { width:100% !important; }
            .ah-pad { padding-left:24px !important; padding-right:24px !important; }
            .ah-stack { display:block !important; width:100% !important; text-align:left !important; }
            .ah-amount { font-size:28px !important; }
            .ah-logo { height:40px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f6f6f7; -webkit-font-smoothing:antialiased;">

{{-- Inbox preview line: shown next to the subject, invisible in the body. --}}
<div style="display:none; max-height:0; overflow:hidden; opacity:0; visibility:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#f6f6f7;">
    @yield('preheader')
    &#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f6f6f7;">
    <tr>
        <td align="center" style="padding:32px 12px;">

            <table role="presentation" class="ah-shell" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background-color:#ffffff; border-radius:14px; overflow:hidden; box-shadow:0 1px 3px rgba(9,9,11,0.10);">

                {{-- Brand bar: noir background COLOUR carries the brand even with
                     images blocked; the white-on-dark wordmark sits bare on it,
                     which is the surface that artwork is drawn for. --}}
                <tr>
                    <td style="background-color:#000000; padding:26px 36px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td align="left" style="font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:17px; line-height:26px; font-weight:700; color:#f6f6f7; letter-spacing:-0.2px;">
                                    @if ($isUploadedLogo)
                                        {{-- Light plate: keeps an arbitrary uploaded logo legible on the noir band regardless of its own colour. --}}
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff; border-radius:8px;">
                                            <tr>
                                                <td style="padding:8px 14px;">
                                                    <img src="{{ $logoSrc }}" alt="{{ $brandName }}" height="32" class="ah-logo ah-logo-uploaded"
                                                         style="display:block; height:32px; width:auto; max-width:160px; border:0; outline:none;" />
                                                </td>
                                            </tr>
                                        </table>
                                    @else
                                        {{-- 2.07:1 wordmark: sized off the old 120px footprint so the
                                             bar keeps its width, and width:auto keeps the mobile
                                             height override from stretching the lettering. --}}
                                        <img src="{{ $logoSrc }}" alt="{{ $brandName }}" width="120" height="58" class="ah-logo"
                                             style="display:block; width:auto; height:58px; border:0; outline:none; text-decoration:none; color:#f6f6f7; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:17px; font-weight:700;" />
                                    @endif
                                </td>
                                <td align="right" valign="middle" style="font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:11px; line-height:18px; font-weight:600; color:#b0b1b4; text-transform:uppercase; letter-spacing:1.2px;">
                                    Pickleball Courts
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Status accent strip: green confirmed, red rejected, ash neutral. --}}
                <tr>
                    <td style="height:4px; line-height:4px; font-size:0; background-color:@yield('accent', '#b0b1b4');">&nbsp;</td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td class="ah-pad" style="padding:36px;">
                        @yield('content')
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td class="ah-pad" style="padding:24px 36px 30px; background-color:#f6f6f7; border-top:1px solid #e4e4e6;">
                        <p style="margin:0 0 6px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; line-height:19px; color:#5a5a5c;">
                            @if (! empty($company['address']))
                                {{ $company['address'] }}<br />
                            @endif
                            @if (! empty($company['phone']))
                                {{ $company['phone'] }}@if (! empty($company['email'])) &nbsp;&middot;&nbsp; @endif
                            @endif
                            @if (! empty($company['email']))
                                <a href="mailto:{{ $company['email'] }}" style="color:#48494B; text-decoration:none;">{{ $company['email'] }}</a>
                            @endif
                        </p>
                        <p style="margin:0; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:11px; line-height:18px; color:#87878b;">
                            This is an automated message about your booking &mdash; please keep it for your records.
                        </p>
                    </td>
                </tr>

            </table>

            <p style="margin:18px 0 0; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:11px; line-height:17px; color:#87878b;">
                &copy; {{ now()->year }} {{ $brandName }}. All rights reserved.
            </p>

        </td>
    </tr>
</table>

</body>
</html>
