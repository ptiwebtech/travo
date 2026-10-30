{{-- Travo email calendar: shared layout. The stage content is in emails/travo/lifecycle/{stage}.blade.php --}}
@php
    $navy = '#0b1220'; $blue = '#2563eb'; $ink = '#0f172a'; $body = '#334155'; $muted = '#64748b'; $line = '#e2e8f0'; $soft = '#f6f8fb';
    $font = "font-family:'Helvetica Neue',Helvetica,Arial,sans-serif";
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<title>{{ $subject }}</title>
<style>
  @media (max-width:620px){ .px{padding-left:20px!important;padding-right:20px!important} .h1{font-size:28px!important} .col{display:block!important;width:100%!important} .stat{display:block!important;width:100%!important;border-left:0!important;border-top:1px solid #e2e8f0!important} }
  a{color:{{ $blue }}}
</style>
</head>
<body style="margin:0;padding:0;background:#eef2f7;{{ $font }};color:{{ $ink }};-webkit-text-size-adjust:100%">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">{{ $preheader }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7">
<tr><td align="center" style="padding:24px 10px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e3e8ef">

  {{-- brand bar --}}
  <tr><td class="px" style="background:{{ $navy }};padding:22px 36px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
      <td><a href="{{ $linkFor('logo') }}" style="text-decoration:none"><img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo" height="30" style="display:block;height:30px;border:0;color:#ffffff;font-size:22px;font-weight:bold"></a></td>
      <td align="right" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#93a7c7">Logistics · Travel · Utilities</td>
    </tr></table>
  </td></tr>

  @include('emails.travo.lifecycle.' . $stage)

  {{-- sign-off --}}
  <tr><td class="px" style="padding:8px 36px 30px">
    <p style="margin:0;font-size:15px;line-height:1.65;color:{{ $body }}">Questions, or need something unusual moved? Reply to this email or <a href="{{ $whatsapp }}" style="color:{{ $blue }};font-weight:bold">message us on WhatsApp</a>. A real person will answer.</p>
    <p style="margin:18px 0 0;font-size:15px;line-height:1.6;color:{{ $body }}">The Travo team</p>
  </td></tr>

  @include('emails.travo._network-promo', ['pad' => 36])
  <tr><td style="height:18px;line-height:18px;font-size:0">&nbsp;</td></tr>

  {{-- footer --}}
  <tr><td class="px" style="background:{{ $soft }};padding:22px 36px;border-top:1px solid {{ $line }}">
    <p style="margin:0;font-size:12px;line-height:1.7;color:#7b8798">
      Book on the web at <a href="{{ $linkFor('footer') }}" style="color:#7b8798">travo.ng</a> or in the Travo app for
      <a href="https://apps.apple.com/us/app/travo-ng/id6769794240" style="color:#7b8798">iPhone</a> and
      <a href="https://play.google.com/store/apps/details?id=com.travong.app" style="color:#7b8798">Android</a>.<br>
      You're getting this because you have a Travo account. {{ $address }}<br>
      <a href="{{ $unsub }}" style="color:#7b8798;text-decoration:underline">Unsubscribe from these emails</a> (emails about your own orders will still arrive).
    </p>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
