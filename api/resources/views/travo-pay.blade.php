<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Pay securely | Travo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
@include('quote-share-styles')
<style>
.muted{color:#9fb0cc;margin:0 0 16px;line-height:1.6}
.pay-amt{font-family:'Space Grotesk',Inter,sans-serif;font-size:40px;font-weight:700;color:#fff;margin:2px 0 4px}
.kv{width:100%;border-collapse:collapse;margin:14px 0 4px}
.kv td{padding:9px 0;border-bottom:1px solid rgba(159,176,204,.16);font-size:15px;vertical-align:top}
.kv td:first-child{color:#9fb0cc;font-size:13px;width:44%}
.kv tr.total td{font-weight:700;color:#fff;font-size:16px}
.paybtn{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;margin-top:18px;padding:16px 20px;border:0;border-radius:14px;background:#16a34a;color:#fff;font:inherit;font-size:17px;font-weight:700;cursor:pointer}
.paybtn:disabled{opacity:.6}
.cards{display:flex;gap:8px;justify-content:center;margin-top:10px;color:#9fb0cc;font-size:12px;align-items:center;flex-wrap:wrap}
.cards span{border:1px solid rgba(159,176,204,.3);border-radius:6px;padding:2px 7px}
.alt{margin-top:22px;padding-top:16px;border-top:1px solid rgba(159,176,204,.16)}
.alt h3{margin:0 0 6px;font-size:15px;color:#fff}
.ok{display:inline-flex;width:56px;height:56px;border-radius:50%;align-items:center;justify-content:center;font-size:30px;margin-bottom:6px}
.ok.g{background:rgba(74,222,128,.15);color:#4ade80}.ok.r{background:rgba(248,113,113,.15);color:#f87171}.ok.y{background:rgba(250,204,21,.15);color:#facc15}
.links{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.links a{flex:1 1 180px;text-align:center}
.secure{font-size:12px;color:#9fb0cc;margin-top:12px;text-align:center}
</style>
</head>
<body>
<header class="top">
  <a href="https://travo.ng/" class="logo"><img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo.ng" width="118" height="32"></a>
</header>
<main class="wrap">
<section class="card cta">
@if ($mode === 'order')
  @php $q = $st['q']; @endphp
  <p class="eyebrow-l">Pay for your booking</p>
  @if ($st['blocked'] === 'paid')
    <div class="ok g">✓</div>
    <h2>This order is paid.</h2>
    <p class="muted">Thank you. You can follow it on the tracking page.</p>
    <div class="links"><a class="btn btn-lg" href="{{ $trackUrl }}">Track this order</a></div>
  @elseif ($st['blocked'])
    <h2>{{ $q['orderType'] }}</h2>
    <p class="muted">{{ $st['blocked'] }}</p>
    <div class="links"><a class="btn btn-lg" href="{{ $whatsapp }}" target="_blank" rel="noopener">Message us on WhatsApp</a></div>
  @else
    <h2>{{ $q['orderType'] }}</h2>
    @if (!empty($q['service']))<p class="muted" style="margin:-6px 0 6px">{{ $q['service'] }}</p>@endif
    <table class="kv">
      @if (!empty($q['pickup']))<tr><td>From</td><td>{{ $q['pickup']['label'] }}</td></tr>@endif
      @if (!empty($q['dropoff']))<tr><td>To</td><td>{{ $q['dropoff']['label'] }}</td></tr>@endif
      <tr><td>Reference</td><td>{{ $st['ref'] }}</td></tr>
      @if ($st['paid'] > 0)
      <tr><td>Order total</td><td>{{ $money($st['due']) }}</td></tr>
      <tr><td>Already paid</td><td>{{ $money($st['paid']) }}</td></tr>
      @endif
      <tr class="total"><td>{{ $st['paid'] > 0 ? 'Left to pay' : 'Amount to pay' }}</td><td>{{ $money($st['left']) }}</td></tr>
    </table>
    @if ($ready)
      <form method="post" action="" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Opening secure payment…'">
        <button class="paybtn" type="submit">🔒 Pay {{ $money($st['left']) }} by card</button>
      </form>
      <div class="cards"><span>Verve</span><span>Mastercard</span><span>Visa</span> · secured by Quickteller (Interswitch)</div>
    @endif
    <div class="alt">
      <h3>{{ $ready ? 'Or pay by bank transfer' : 'Pay by bank transfer' }}</h3>
      <table class="kv">
        <tr><td>Account name</td><td>{{ $bank['name'] }}</td></tr>
        <tr><td>Bank</td><td>{{ $bank['bank'] }}</td></tr>
        <tr><td>Account number</td><td><b>{{ $bank['number'] }}</b></td></tr>
        <tr><td>Payment reference</td><td><b>{{ $st['ref'] }}</b></td></tr>
      </table>
      <p class="muted" style="margin-top:10px">Use the reference so we can match your transfer. Questions? <a href="{{ $whatsapp }}" target="_blank" rel="noopener" style="color:#93c5fd">Message us on WhatsApp</a>.</p>
    </div>
  @endif

@elseif ($mode === 'redirect')
  <p class="eyebrow-l">Secure card payment</p>
  <h2>Taking you to Quickteller…</h2>
  <div class="pay-amt">{{ $amount }}</div>
  <p class="muted">You'll enter your card details on Quickteller's secure page, then come straight back here.</p>
  <form id="isw" method="post" action="{{ $form['action'] }}">
    @foreach ($form['fields'] as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    <button class="paybtn" type="submit">Continue to secure payment</button>
  </form>
  <p class="secure">🔒 Travo never sees or stores your card details.</p>
  <script>setTimeout(function () { document.getElementById('isw').submit(); }, 600);</script>

@elseif ($mode === 'result')
  @if ($p->status === 'paid')
    <div class="ok g">✓</div>
    <p class="eyebrow-l">Payment received</p>
    <h2>Thank you, {{ $amount }} paid.</h2>
    <p class="muted">{{ $p->purpose === 'topup' ? 'Your Travo wallet has been topped up.' : 'Your order is confirmed and we\'ve emailed you a receipt. We\'ll keep you updated as it moves.' }} Reference {{ $p->txn_ref }}.</p>
    <div class="links">
      @if ($trackUrl)<a class="btn btn-lg" href="{{ $trackUrl }}">Track your order</a>@endif
      <a class="btn btn-lg" href="https://travo.ng/fleet-ops#wallet" style="{{ $trackUrl ? 'background:transparent;border:1px solid rgba(159,176,204,.4)' : '' }}">Open my wallet</a>
    </div>
  @elseif ($p->status === 'pending')
    <div class="ok y">…</div>
    <p class="eyebrow-l">Checking your payment</p>
    <h2>We're confirming your payment.</h2>
    <p class="muted">This can take a few minutes. You don't need to pay again: as soon as Quickteller confirms it, {{ $p->purpose === 'topup' ? 'your wallet is topped up' : 'your order is marked paid and you get an email' }}. Reference {{ $p->txn_ref }}.</p>
    <div class="links"><a class="btn btn-lg" href="">Check again</a></div>
  @else
    <div class="ok r">!</div>
    <p class="eyebrow-l">Payment not completed</p>
    <h2>Your card was not charged.</h2>
    <p class="muted">{{ $p->resp_desc ?: 'The payment was cancelled or declined.' }} You can try again, use another card, or pay by bank transfer.</p>
    <div class="links"><a class="btn btn-lg" href="{{ $retryUrl }}">Try again</a><a class="btn btn-lg" href="{{ $whatsapp }}" target="_blank" rel="noopener" style="background:transparent;border:1px solid rgba(159,176,204,.4)">WhatsApp us</a></div>
  @endif

@else
  <p class="eyebrow-l">Travo payments</p>
  <h2>{{ $title }}</h2>
  <p class="muted">{{ $text }}</p>
  <div class="links">@if (!empty($back))<a class="btn btn-lg" href="{{ $back }}">Back</a>@endif<a class="btn btn-lg" href="{{ $whatsapp }}" target="_blank" rel="noopener">Message us on WhatsApp</a></div>
@endif
</section>
</main>
</body>
</html>
