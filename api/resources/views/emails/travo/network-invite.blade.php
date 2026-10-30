{{-- Travo "My Network" invite / reminder. Sent only to people a member ticked; one invite + at most one reminder. --}}
@php
    $navy = '#0b1220'; $blue = '#2563eb'; $ink = '#0f172a'; $body = '#334155'; $muted = '#64748b'; $line = '#e2e8f0'; $soft = '#f6f8fb';
    $font = "font-family:'Helvetica Neue',Helvetica,Arial,sans-serif";
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $subject }}</title>
<style>@media (max-width:620px){ .px{padding-left:20px!important;padding-right:20px!important} } a{color:{{ $blue }}}</style>
</head>
<body style="margin:0;padding:0;background:#eef2f7;{{ $font }};color:{{ $ink }}">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">{{ $inviterFirst }} uses Travo to book deliveries, haulage, travel and utilities in Nigeria, and thinks you'd find it useful.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7">
<tr><td align="center" style="padding:24px 10px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e3e8ef">
  <tr><td class="px" style="background:{{ $navy }};padding:22px 36px">
    <img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo" height="30" style="display:block;height:30px;border:0;color:#ffffff;font-size:22px;font-weight:bold">
  </td></tr>
  <tr><td class="px" style="padding:34px 36px 8px">
    <p style="margin:0 0 10px;font-size:15px;color:{{ $muted }}">{{ $name ? 'Hi ' . $name . ',' : 'Hello,' }}</p>
    <h1 style="margin:0 0 16px;font-size:26px;line-height:1.25;color:{{ $ink }}">
      @if ($reminder){{ $inviterFirst }}'s invite to Travo is still open @else{{ $inviterName }} has invited you to Travo @endif
    </h1>
    <p style="margin:0 0 14px;font-size:16px;line-height:1.65;color:{{ $body }}">Travo is where businesses and families in Nigeria book and track deliveries, haulage, bus and car travel, and utilities, with a clear price before you pay and live tracking after.</p>
    <p style="margin:0 0 24px;font-size:16px;line-height:1.65;color:{{ $body }}">Join through {{ $inviterFirst }}'s invite and you'll be connected on Travo. Getting a quote is free.</p>
    <table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:10px;background:{{ $blue }}">
      <a href="{{ $joinUrl }}" style="display:inline-block;padding:14px 26px;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:10px">Accept the invite</a>
    </td></tr></table>
    <p style="margin:22px 0 0;font-size:13px;line-height:1.6;color:{{ $muted }}">Or paste this link into your browser:<br><a href="{{ $joinUrl }}" style="color:{{ $muted }};word-break:break-all">{{ $joinUrl }}</a></p>
  </td></tr>
  <tr><td class="px" style="padding:22px 36px 30px">
    <p style="margin:0;font-size:15px;line-height:1.65;color:{{ $body }}">Questions? Reply to this email or <a href="{{ $whatsapp }}" style="color:{{ $blue }};font-weight:bold">message us on WhatsApp</a>.</p>
  </td></tr>
  <tr><td class="px" style="background:{{ $soft }};padding:20px 36px;border-top:1px solid {{ $line }}">
    <p style="margin:0;font-size:12px;line-height:1.7;color:#7b8798">
      You're getting this because {{ $inviterName }} entered your address to invite you to Travo. We haven't added you to any list,
      @if ($reminder) and this is the only reminder you'll get. @else and you'll get at most one reminder. @endif<br>
      <a href="{{ $optOutUrl }}" style="color:#7b8798;text-decoration:underline">Don't send me Travo invites</a> · {{ $address }}
    </p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
