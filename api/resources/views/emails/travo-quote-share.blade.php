<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $q['orderType'] }} quote</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden">
  <tr><td style="background:#111a2b;padding:18px 24px">
    <img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo.ng" height="30" style="display:block;height:30px;border:0">
  </td></tr>
  <tr><td style="padding:24px 24px 8px">
    <p style="margin:0 0 12px;font-size:15px">Hi {{ $name }},</p>
    <p style="margin:0 0 18px;font-size:15px;line-height:1.5">{{ $sender }} shared a quote with you on Travo.</p>
    <p style="margin:0;font-size:13px;color:#64748b;text-transform:uppercase;letter-spacing:.06em">{{ $q['orderType'] }}</p>
    @if ($q['service'])<p style="margin:4px 0 0;font-size:14px;color:#475569">{{ $q['service'] }}</p>@endif
    @if ($q['price'])<p style="margin:14px 0 0;font-size:34px;font-weight:bold;color:#0f172a">{{ $q['price'] }}</p>@endif
  </td></tr>
  <tr><td style="padding:8px 24px 4px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;border-top:1px solid #e2e8f0">
      @if ($q['pickup'])<tr><td style="padding:10px 0;color:#64748b;width:70px">From</td><td style="padding:10px 0;font-weight:bold">{{ $q['pickup']['label'] }}</td></tr>@endif
      @if ($q['dropoff'])<tr><td style="padding:10px 0;color:#64748b;border-top:1px solid #f1f5f9">To</td><td style="padding:10px 0;font-weight:bold;border-top:1px solid #f1f5f9">{{ $q['dropoff']['label'] }}</td></tr>@endif
      @if ($q['scheduled'])<tr><td style="padding:10px 0;color:#64748b;border-top:1px solid #f1f5f9">When</td><td style="padding:10px 0;border-top:1px solid #f1f5f9">{{ $q['scheduled'] }}</td></tr>@endif
      @foreach ($q['lines'] as $l)<tr><td colspan="2" style="padding:6px 0;border-top:1px solid #f1f5f9;color:#475569">{{ $l['label'] }}<span style="float:right">{{ $l['amount'] }}</span></td></tr>@endforeach
    </table>
  </td></tr>
  <tr><td align="center" style="padding:22px 24px 8px">
    <a href="{{ $q['url'] }}" style="display:inline-block;background:#3b82f6;color:#ffffff;text-decoration:none;font-weight:bold;font-size:16px;padding:14px 28px;border-radius:10px">View the full quote</a>
  </td></tr>
  <tr><td style="padding:16px 24px 24px">
    <div style="background:#eff6ff;border-radius:12px;padding:16px 18px">
      <p style="margin:0 0 6px;font-weight:bold;font-size:15px">Need something moved too?</p>
      <p style="margin:0 0 12px;font-size:14px;line-height:1.5;color:#334155">Get instant quotes for parcels, bus tickets, drivers, haulage and more with a free Travo account.</p>
      <a href="https://travo.ng/onboard?utm_source=quote&amp;utm_medium=email&amp;utm_campaign=quote-share" style="color:#1d4ed8;font-weight:bold;font-size:14px">Create a free account &rarr;</a>
    </div>
  </td></tr>
  <tr><td style="padding:0 24px 22px;font-size:12px;color:#94a3b8;line-height:1.5">
    Reference {{ $q['ref'] }}. Prices are estimates from when the quote was made and are confirmed when the order is booked.
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
