{{-- Travo customer email: one layout for every step (quote, payment received, dispatched, delivered, shared quote). --}}
@php
    $navy = '#0b1220'; $blue = '#2563eb'; $green = '#16a34a'; $ink = '#0f172a'; $muted = '#64748b'; $line = '#e2e8f0';
    $keys = ['quoted', 'paid', 'dispatched', 'delivered'];
    $curIx = array_search($current, $keys, true);
    $short = ['quoted' => 'Quoted', 'paid' => 'Paid', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered'];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:{{ $ink }};-webkit-text-size-adjust:100%">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">{{ $title }} · {{ $q['orderType'] }}@if($q['price']) · {{ $q['price'] }}@endif</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;padding:24px 12px">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e9f0">

  {{-- header --}}
  <tr><td style="background:{{ $navy }};padding:20px 28px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
      <td><a href="https://travo.ng" style="text-decoration:none"><img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo" height="30" style="display:block;height:30px;border:0;color:#ffffff;font-size:22px;font-weight:bold"></a></td>
      <td align="right" style="font-size:12px;color:#9fb0cc">Ref <span style="color:#ffffff;font-weight:bold">{{ $ref }}</span></td>
    </tr></table>
  </td></tr>

  {{-- progress --}}
  <tr><td style="padding:22px 28px 6px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed"><tr>
      @foreach ($keys as $i => $k)
        @php
            $done = $i <= $curIx;
            $isCur = $i === $curIx;
            $bar = $isCur ? $blue : ($done ? $green : $line);
            $col = $isCur ? $blue : ($done ? $green : '#94a3b8');
        @endphp
        <td style="padding:0 3px" valign="top">
          <div style="height:6px;border-radius:6px;background:{{ $bar }};font-size:0;line-height:0">&nbsp;</div>
          <div style="padding-top:8px;font-size:12px;font-weight:bold;color:{{ $col }}">{{ $done && !$isCur ? '✓ ' : '' }}{{ $short[$k] }}</div>
        </td>
      @endforeach
    </tr></table>
  </td></tr>

  {{-- message --}}
  <tr><td style="padding:18px 28px 4px">
    <span style="display:inline-block;font-size:11px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;color:{{ $blue }};background:#eff6ff;border-radius:999px;padding:5px 10px">{{ $eyebrow }}</span>
    <h1 style="margin:12px 0 0;font-size:24px;line-height:1.25;color:{{ $ink }}">{{ $title }}</h1>
    <p style="margin:14px 0 0;font-size:15px;line-height:1.6;color:#334155">Hi {{ $first ?: 'there' }},</p>
    <p style="margin:8px 0 0;font-size:15px;line-height:1.6;color:#334155">{{ $intro }}</p>
  </td></tr>

  {{-- order summary --}}
  <tr><td style="padding:20px 28px 4px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid {{ $line }};border-radius:12px">
      <tr><td style="padding:16px 18px 6px">
        <div style="font-size:12px;color:{{ $muted }};text-transform:uppercase;letter-spacing:.06em">{{ $q['orderType'] }}</div>
        @if ($q['service'])<div style="margin-top:3px;font-size:14px;color:#334155">{{ $q['service'] }}</div>@endif
        @if ($q['price'])<div style="margin-top:10px;font-size:30px;font-weight:bold;color:{{ $ink }}">{{ $q['price'] }}</div>@endif
      </td></tr>
      <tr><td style="padding:4px 18px 12px">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">
          @if (!empty($showDetails) && !empty($pickupFull))
            <tr><td style="padding:9px 0;color:{{ $muted }};width:96px;border-top:1px solid #f1f5f9;vertical-align:top">Pickup</td><td style="padding:9px 0;font-weight:bold;border-top:1px solid #f1f5f9;line-height:1.45">{{ $pickupFull['text'] }}<br><a href="{{ $pickupFull['map'] }}" style="font-size:12px;font-weight:normal;color:{{ $blue }}">Open in Google Maps &rarr;</a></td></tr>
          @elseif ($q['pickup'])<tr><td style="padding:9px 0;color:{{ $muted }};width:96px;border-top:1px solid #f1f5f9">From</td><td style="padding:9px 0;font-weight:bold;border-top:1px solid #f1f5f9">{{ $q['pickup']['label'] }}</td></tr>@endif
          @if (!empty($showDetails) && !empty($dropoffFull))
            <tr><td style="padding:9px 0;color:{{ $muted }};border-top:1px solid #f1f5f9;vertical-align:top">Drop-off</td><td style="padding:9px 0;font-weight:bold;border-top:1px solid #f1f5f9;line-height:1.45">{{ $dropoffFull['text'] }}<br><a href="{{ $dropoffFull['map'] }}" style="font-size:12px;font-weight:normal;color:{{ $blue }}">Open in Google Maps &rarr;</a></td></tr>
          @elseif ($q['dropoff'])<tr><td style="padding:9px 0;color:{{ $muted }};border-top:1px solid #f1f5f9">To</td><td style="padding:9px 0;font-weight:bold;border-top:1px solid #f1f5f9">{{ $q['dropoff']['label'] }}</td></tr>@endif
          @if (!empty($showDetails) && !empty($distance))<tr><td style="padding:9px 0;color:{{ $muted }};border-top:1px solid #f1f5f9">Distance</td><td style="padding:9px 0;border-top:1px solid #f1f5f9">{{ $distance }}</td></tr>@endif
          @if (!empty($showDetails) && !empty($client['name']))<tr><td style="padding:9px 0;color:{{ $muted }};border-top:1px solid #f1f5f9">Client</td><td style="padding:9px 0;border-top:1px solid #f1f5f9">{{ $client['name'] }}</td></tr>@endif
          @if (!empty($showDetails) && !empty($client['phone']) && $client['phone'] !== 'N/A')<tr><td style="padding:9px 0;color:{{ $muted }};border-top:1px solid #f1f5f9">Client phone</td><td style="padding:9px 0;border-top:1px solid #f1f5f9"><a href="tel:{{ preg_replace('/[^0-9+]/', '', $client['phone']) }}" style="color:{{ $blue }}">{{ $client['phone'] }}</a></td></tr>@endif
          @if ($q['scheduled'])<tr><td style="padding:9px 0;color:{{ $muted }};border-top:1px solid #f1f5f9">When</td><td style="padding:9px 0;border-top:1px solid #f1f5f9">{{ $q['scheduled'] }}</td></tr>@endif
          @if (!empty($driver) && in_array($step, ['dispatched', 'delivered'], true))<tr><td style="padding:9px 0;color:{{ $muted }};border-top:1px solid #f1f5f9">Driver</td><td style="padding:9px 0;border-top:1px solid #f1f5f9">{{ $driver }}</td></tr>@endif
          @if (!empty($tracking))<tr><td style="padding:9px 0;color:{{ $muted }};border-top:1px solid #f1f5f9">Tracking no.</td><td style="padding:9px 0;border-top:1px solid #f1f5f9">{{ $tracking }}</td></tr>@endif
          @foreach ($q['lines'] as $l)
            <tr><td colspan="2" style="padding:7px 0;border-top:1px solid #f1f5f9;color:#475569;font-size:13px">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="font-size:13px;color:#475569">{{ $l['label'] }}</td><td align="right" style="font-size:13px;color:#475569;white-space:nowrap">{{ $l['amount'] }}</td></tr></table>
            </td></tr>
          @endforeach
        </table>
      </td></tr>
    </table>
  </td></tr>

  {{-- payment details (quote stage only) --}}
  @if ($showPay)
  <tr><td style="padding:16px 28px 4px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f7ff;border:1px solid #cfe3ff;border-radius:12px">
      <tr><td style="padding:16px 18px">
        <div style="font-size:15px;font-weight:bold;color:{{ $ink }}">Pay to confirm your booking</div>
        @if (class_exists(\App\Support\TravoCard::class) && \App\Support\TravoCard::ready())
        {{-- travo-card --}}<table role="presentation" cellpadding="0" cellspacing="0" style="margin:12px 0 4px"><tr><td style="border-radius:10px;background:#16a34a">
          <a href="{{ str_replace('/quote/', '/pay/', $q['url']) }}" style="display:inline-block;padding:13px 22px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:10px">&#128274; Pay {{ $leftToPay ?? $q['price'] }} by card</a>
        </td></tr></table>
        <div style="font-size:12px;color:#64748b;margin:2px 0 10px">Verve, Mastercard or Visa, secured by Quickteller. Or pay by bank transfer:</div>
        @endif
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;font-size:14px">
          <tr><td style="padding:5px 0;color:{{ $muted }};width:150px">Account name</td><td style="padding:5px 0;font-weight:bold">{{ $bank['name'] }}</td></tr>
          <tr><td style="padding:5px 0;color:{{ $muted }}">Bank</td><td style="padding:5px 0;font-weight:bold">{{ $bank['bank'] }}</td></tr>
          <tr><td style="padding:5px 0;color:{{ $muted }}">Account number</td><td style="padding:5px 0;font-weight:bold;font-size:18px;letter-spacing:.04em">{{ $bank['number'] }}</td></tr>
          <tr><td style="padding:5px 0;color:{{ $muted }}">Payment reference</td><td style="padding:5px 0"><span style="font-weight:bold;font-size:16px;background:#ffffff;border:1px dashed #93c5fd;border-radius:6px;padding:3px 8px">{{ $ref }}</span></td></tr>
          @if (!empty($leftToPay))
          <tr><td style="padding:8px 0 3px;color:{{ $muted }}">Order total</td><td style="padding:8px 0 3px">{{ $q['price'] }}</td></tr>
          <tr><td style="padding:3px 0;color:{{ $muted }}">Paid from wallet</td><td style="padding:3px 0">{{ $walletPaidText }}</td></tr>
          <tr><td style="padding:5px 0;color:{{ $muted }}">Left to pay</td><td style="padding:5px 0;font-weight:bold;font-size:18px;color:{{ $ink }}">{{ $leftToPay }}</td></tr>
          @elseif ($q['price'])<tr><td style="padding:8px 0 5px;color:{{ $muted }}">Amount to pay</td><td style="padding:8px 0 5px;font-weight:bold;font-size:18px;color:{{ $ink }}">{{ $q['price'] }}</td></tr>@endif
        </table>
        <p style="margin:10px 0 0;font-size:13px;line-height:1.5;color:#475569">Please use the reference above so we can match your payment. Paying in USD, GBP, EUR, GHS, ZAR or KSh? <a href="{{ $whatsapp }}" style="color:{{ $blue }};font-weight:bold">Message us on WhatsApp</a>.</p>
      </td></tr>
    </table>
  </td></tr>
  @endif

  {{-- call to action --}}
  <tr><td align="center" style="padding:24px 28px 6px">
    <a href="{{ $ctaUrl }}" style="display:inline-block;background:{{ $blue }};color:#ffffff;text-decoration:none;font-weight:bold;font-size:16px;padding:14px 30px;border-radius:10px">{{ $cta }}</a>
    @if (!empty($trackUrl) && $ctaUrl !== $trackUrl)
      <div style="margin-top:12px;font-size:13px"><a href="{{ $trackUrl }}" style="color:{{ $blue }};font-weight:bold">{{ $step === 'delivered' ? 'View your order details' : 'Track your order' }} &rarr;</a></div>
    @elseif ($step === 'delivered')
      <div style="margin-top:12px;font-size:13px"><a href="{{ $orderUrl }}" style="color:{{ $muted }}">View this order</a></div>
    @endif
  </td></tr>

  @if (!empty($promo))
  <tr><td style="padding:16px 28px 0">
    <div style="background:#f8fafc;border-radius:12px;padding:16px 18px">
      <p style="margin:0 0 6px;font-weight:bold;font-size:15px">Need something moved too?</p>
      <p style="margin:0 0 10px;font-size:14px;line-height:1.5;color:#334155">Get instant quotes for parcels, bus tickets, drivers, haulage and more with a free Travo account.</p>
      <a href="https://travo.ng/onboard?utm_source=quote&amp;utm_medium=email&amp;utm_campaign=quote-share" style="color:{{ $blue }};font-weight:bold;font-size:14px">Create a free account &rarr;</a>
    </div>
  </td></tr>
  @endif

  @include('emails.travo._network-promo', ['pad' => 28])

  {{-- help + footer --}}
  <tr><td style="padding:22px 28px 6px">
    <p style="margin:0;font-size:14px;line-height:1.6;color:#334155">Questions? Reply to this email or <a href="{{ $whatsapp }}" style="color:{{ $blue }};font-weight:bold">chat with us on WhatsApp</a>.</p>
  </td></tr>
  <tr><td style="padding:16px 28px 24px">
    <div style="border-top:1px solid {{ $line }};padding-top:14px;font-size:12px;line-height:1.6;color:#94a3b8">
      Travo Services Limited · <a href="https://travo.ng" style="color:#94a3b8">travo.ng</a><br>
      @if ($step === 'quote')Prices are confirmed once payment is received. @endif You are receiving this email about your Travo order {{ $ref }}.
    </div>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
