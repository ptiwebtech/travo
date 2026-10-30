{{-- Travo chat: "you have a new message". At most one per conversation per {{ $hours }} hours. --}}
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
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">{{ $fromName }}: {{ \Illuminate\Support\Str::limit($preview, 90) }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7">
<tr><td align="center" style="padding:24px 10px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e3e8ef">
  <tr><td class="px" style="background:{{ $navy }};padding:22px 36px">
    <img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo" height="30" style="display:block;height:30px;border:0;color:#ffffff;font-size:22px;font-weight:bold">
  </td></tr>
  <tr><td class="px" style="padding:32px 36px 8px">
    <p style="margin:0 0 10px;font-size:15px;color:{{ $muted }}">{{ $name ? 'Hi ' . $name . ',' : 'Hello,' }}</p>
    <h1 style="margin:0 0 18px;font-size:24px;line-height:1.3;color:{{ $ink }}">You have a new message from {{ $fromName }}</h1>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px">
      <tr><td style="background:{{ $soft }};border:1px solid {{ $line }};border-left:4px solid {{ $blue }};border-radius:12px;padding:16px 18px">
        <p style="margin:0 0 6px;font-size:13px;font-weight:bold;color:{{ $muted }}">{{ $fromName }}</p>
        <p style="margin:0;font-size:16px;line-height:1.6;color:{{ $body }}">{{ $preview }}</p>
        @if ($hasQuote)<p style="margin:10px 0 0;font-size:14px;color:{{ $blue }}">📄 Includes a quote</p>@endif
        @if ($more > 0)<p style="margin:10px 0 0;font-size:13px;color:{{ $muted }}">+ {{ $more }} more {{ $more === 1 ? 'message' : 'messages' }}</p>@endif
      </td></tr>
    </table>
    <table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:10px;background:{{ $blue }}">
      <a href="{{ $openUrl }}" style="display:inline-block;padding:14px 26px;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:10px">Open the chat and reply</a>
    </td></tr></table>
    <p style="margin:18px 0 0;font-size:13px;line-height:1.6;color:{{ $muted }}">Log in to Travo and tap <b>Chat</b> at the top to read the whole conversation. Please reply in the app rather than to this email, so everything stays in one place.</p>
  </td></tr>
  <tr><td class="px" style="padding:22px 36px 30px">
    <p style="margin:0;font-size:14px;line-height:1.6;color:{{ $muted }}">To keep your inbox quiet, we email you about a conversation at most once every {{ $hours }} hours. Any further replies will be waiting in the app.</p>
  </td></tr>
  <tr><td class="px" style="background:{{ $soft }};padding:20px 36px;border-top:1px solid {{ $line }}">
    <p style="margin:0;font-size:12px;line-height:1.7;color:#7b8798">
      You're getting this because someone sent you a message on Travo.<br>
      <a href="{{ $offUrl }}" style="color:#7b8798;text-decoration:underline">Stop emailing me about chat messages</a> · Travo Services Limited, Lagos, Nigeria
    </p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
