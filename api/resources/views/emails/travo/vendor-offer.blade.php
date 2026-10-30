{{-- Travo job offer to a vendor on the service. Areas only: never the customer's name, contact or street address. --}}
@php
    $navy = '#0b1220'; $blue = '#2563eb'; $green = '#16a34a'; $ink = '#0f172a'; $body = '#334155'; $muted = '#64748b'; $line = '#e2e8f0'; $soft = '#f6f8fb';
    $font = "font-family:'Helvetica Neue',Helvetica,Arial,sans-serif";
    $rows = array_filter([
        'Job'      => trim($orderType . ($service && $service !== $orderType ? ' · ' . $service : '')),
        'Pickup'   => $pickup,
        'Drop-off' => $dropoff,
        'Distance' => $distanceKm ? $distanceKm . ' km' . ($minutes ? ' (about ' . ($minutes >= 90 ? round($minutes / 60, 1) . ' hours' : $minutes . ' min') . ' drive)' : '') : null,
        'Items'    => $items ? implode(', ', $items) : null,
        'When'     => $when ?: ($created ? 'Booked ' . $created : null),
        'Job ref'  => $ref,
    ]);
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $subject }}</title>
<style>@media (max-width:620px){ .px{padding-left:20px!important;padding-right:20px!important} .k{width:92px!important} } a{color:{{ $blue }}}</style>
</head>
<body style="margin:0;padding:0;background:#eef2f7;{{ $font }};color:{{ $ink }}">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">Earn {{ $offer }}. The first vendors to accept are first in line for this job.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7">
<tr><td align="center" style="padding:24px 10px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e3e8ef">
  <tr><td class="px" style="background:{{ $navy }};padding:22px 36px">
    <img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo" height="30" style="display:block;height:30px;border:0;color:#ffffff;font-size:22px;font-weight:bold">
  </td></tr>
  <tr><td class="px" style="padding:30px 36px 6px">
    <p style="margin:0 0 8px;font-size:15px;color:{{ $muted }}">Hi {{ $vendorName }},</p>
    <h1 style="margin:0 0 6px;font-size:24px;line-height:1.3;color:{{ $ink }}">New job available: earn {{ $offer }}</h1>
    <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:{{ $body }}">A customer has just booked this on Travo. If you can do it at this price, accept it now. <strong>The first vendors to accept are first in line</strong>, and this offer closes in {{ $hoursLeft }} hours or as soon as the job is assigned.</p>
  </td></tr>
  <tr><td class="px" style="padding:0 36px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ecfdf3;border:1px solid #bbf7d0;border-radius:12px">
      <tr><td style="padding:16px 18px">
        <p style="margin:0;font-size:13px;color:#166534;text-transform:uppercase;letter-spacing:.06em;font-weight:bold">Your price for this job</p>
        <p style="margin:4px 0 0;font-size:30px;font-weight:bold;color:{{ $green }}">{{ $offer }}</p>
      </td></tr>
    </table>
  </td></tr>
  <tr><td class="px" style="padding:18px 36px 4px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">
      @foreach ($rows as $k => $v)
      <tr>
        <td class="k" valign="top" style="width:110px;padding:9px 0;border-bottom:1px solid {{ $line }};font-size:13px;color:{{ $muted }}">{{ $k }}</td>
        <td valign="top" style="padding:9px 0;border-bottom:1px solid {{ $line }};font-size:15px;color:{{ $ink }}">{{ $v }}</td>
      </tr>
      @endforeach
    </table>
    <p style="margin:10px 0 0;font-size:12px;line-height:1.6;color:{{ $muted }}">Exact addresses and contact details are shared with the vendor the job is assigned to.</p>
  </td></tr>
  <tr><td class="px" style="padding:22px 36px 8px">
    <table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:10px;background:{{ $green }}">
      <a href="{{ $url }}" style="display:inline-block;padding:15px 28px;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:10px">Accept this job for {{ $offer }}</a>
    </td></tr></table>
    <p style="margin:14px 0 0;font-size:13px;line-height:1.6;color:{{ $muted }}">Can't do this one? Just ignore this email, or open the link and press "Not this time".</p>
  </td></tr>
  <tr><td class="px" style="padding:18px 36px 28px">
    <p style="margin:0;font-size:15px;line-height:1.65;color:{{ $body }}">Questions? Reply to this email or <a href="{{ $whatsapp }}" style="color:{{ $blue }};font-weight:bold">message us on WhatsApp</a>.</p>
  </td></tr>
  <tr><td class="px" style="background:{{ $soft }};padding:20px 36px;border-top:1px solid {{ $line }}">
    <p style="margin:0;font-size:12px;line-height:1.7;color:#7b8798">
      You're getting this because you're listed as a Travo vendor for this service.<br>
      <a href="{{ $stopUrl }}" style="color:#7b8798;text-decoration:underline">Stop sending me job offers</a> · {{ $address }}
    </p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
