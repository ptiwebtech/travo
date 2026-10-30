@php
    $keys  = array_column($steps, 'key');
    $curIx = array_search($current, $keys, true);
    $short = ['quoted' => 'Quoted', 'paid' => 'Paid', 'dispatched' => 'On its way', 'delivered' => 'Delivered'];
    $share = 'Track my Travo order ' . $ref . ': ' . $url;
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $headline }} · Order {{ $ref }} | Travo</title>
<meta name="description" content="{{ $orderType }} · {{ $headline }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Travo.ng">
<meta property="og:title" content="Order {{ $ref }}: {{ $headline }}">
<meta property="og:description" content="{{ $orderType }}@if($pickup && $dropoff) · {{ $pickup['label'] }} → {{ $dropoff['label'] }}@endif">
<meta property="og:image" content="https://travo.ng/directory/assets/og-image.png">
<meta name="theme-color" content="#111a2b">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
@include('quote-share-styles')
@include('order-track-styles')
</head>
<body>
<header class="top">
  <a href="https://travo.ng/" class="logo"><img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo.ng" width="118" height="32"></a>
  <a href="{{ $whatsapp }}" class="btn btn-sm btn-ghost" target="_blank" rel="noopener">Help</a>
</header>

<main class="wrap">
  {{-- status --}}
  <section class="card">
    <div class="eyebrow">
      <span>Order tracking</span>
      <span class="pill {{ $canceled ? 'pill-off' : ($current === 'quoted' ? 'pill-wait' : '') }}">{{ $headline }}</span>
    </div>
    <h1>{{ $headline }}</h1>
    <p class="service">{{ $sub }}</p>

    @unless ($canceled)
    <ol class="tk-steps" aria-label="Order progress">
      @foreach ($steps as $i => $s)
        @php $cls = $i === $curIx ? 'cur' : ($s['done'] ? 'done' : 'todo'); @endphp
        <li class="{{ $cls }}">
          <span class="tk-dot">{{ $s['done'] && $i !== $curIx ? '✓' : $i + 1 }}</span>
          <span class="tk-name">{{ $short[$s['key']] ?? $s['label'] }}</span>
          @if ($s['done'] && $s['at'])<span class="tk-time">{{ $s['at'] }}</span>@endif
        </li>
      @endforeach
    </ol>
    @endunless

    <dl class="facts">
      <div><dt>Order reference</dt><dd>{{ $ref }}</dd></div>
      @if ($trackingNo)<div><dt>Tracking number</dt><dd>{{ $trackingNo }}</dd></div>@endif
      @if ($updated)<div><dt>Last update</dt><dd>{{ $updated }}</dd></div>@endif
      @if ($driver)<div><dt>Driver</dt><dd>{{ $driver }}</dd></div>@endif
      @if ($vehicle)<div><dt>Vehicle</dt><dd>{{ $vehicle }}</dd></div>@endif
    </dl>
  </section>

  {{-- payment (until payment is received) --}}
  @if ($showPay)
  <section class="card pay" id="pay">
    <h2>Pay to confirm your booking</h2>
    @if (class_exists(\App\Support\TravoCard::class) && \App\Support\TravoCard::ready())
    {{-- travo-card --}}<a class="btn btn-lg" href="{{ str_replace('/quote/', '/pay/', $quoteUrl) }}" style="display:block;text-align:center;background:#16a34a;border-color:#16a34a;margin:4px 0 8px">&#128274; Pay now by card</a>
    <p class="hint" style="margin:0 0 10px">Verve, Mastercard or Visa, secured by Quickteller. Your booking is confirmed straight away. Or pay by bank transfer:</p>
    @endif
    <table class="kv">
      <tr><td>Account name</td><td>{{ $bank['name'] }}</td></tr>
      <tr><td>Bank</td><td>{{ $bank['bank'] }}</td></tr>
      <tr><td>Account number</td><td><button type="button" class="copy big" data-copy="{{ $bank['number'] }}">{{ $bank['number'] }}<small>copy</small></button></td></tr>
      <tr><td>Payment reference</td><td><button type="button" class="copy ref" data-copy="{{ $ref }}">{{ $ref }}<small>copy</small></button></td></tr>
      @php $walletPaid = rescue(fn () => \App\Support\TravoWallet::paymentsForRef($ref), 0, false); @endphp
      @if ($walletPaid > 0 && $price)
      <tr><td>Order total</td><td>{{ $price }}</td></tr>
      <tr><td>Paid from Travo wallet</td><td>{{ \App\Support\TravoWallet::money($walletPaid) }}</td></tr>
      <tr class="total"><td>Left to pay</td><td>{{ \App\Support\TravoWallet::money(max(0, (int) round((float) preg_replace('/[^0-9.]/', '', $price) * 100) - $walletPaid)) }}</td></tr>
      @elseif ($price)<tr class="total"><td>Amount to pay</td><td>{{ $price }}</td></tr>@endif
    </table>
    <p class="hint">Please use the payment reference so we can match your payment. Already paid, or paying in USD, GBP, EUR, GHS, ZAR or KSh? <a href="{{ $whatsapp }}" target="_blank" rel="noopener">Message us on WhatsApp</a>.</p>
  </section>
  @elseif ($paidNote)
  <section class="card paid-note"><span class="tick">✓</span> Payment received{{ $price ? ' · ' . $price : '' }}</section>
  @endif

  {{-- order details --}}
  <section class="card">
    <h2>{{ $orderType }}</h2>
    @if ($service)<p class="service" style="margin-top:-4px">{{ $service }}</p>@endif
    <ol class="route">
      @if ($pickup)<li><span class="dot dot-a">A</span><div><small>From</small><strong>{{ $pickup['label'] }}</strong></div></li>@endif
      @if ($dropoff)<li><span class="dot dot-b">B</span><div><small>To</small><strong>{{ $dropoff['label'] }}</strong></div></li>@endif
    </ol>
    <dl class="facts">
      @if ($distanceKm)<div><dt>Distance</dt><dd>{{ $distanceKm }} km</dd></div>@endif
      @if ($minutes)<div><dt>Est. travel time</dt><dd>{{ $minutes >= 60 ? floor($minutes / 60) . ' h ' . ($minutes % 60) . ' min' : $minutes . ' min' }}</dd></div>@endif
      @if ($created)<div><dt>Booked</dt><dd>{{ $created }}</dd></div>@endif
      @if ($scheduled)<div><dt>Scheduled</dt><dd>{{ $scheduled }}</dd></div>@endif
    </dl>
    @if ($items)
      <div class="items"><h2>Items</h2><ul>@foreach ($items as $it)<li>{{ $it }}</li>@endforeach</ul></div>
    @endif
    @if ($lines && $price)
      <div class="breakdown">
        <h2>Price</h2>
        <table>
          @foreach ($lines as $l)<tr><td>{{ $l['label'] }}</td><td>{{ $l['amount'] }}</td></tr>@endforeach
          <tr class="total"><td>Total</td><td>{{ $price }}</td></tr>
        </table>
      </div>
    @endif
  </section>

  @if ($map)
  <section class="card map">
    {!! $map !!}
    <p class="map-note">Locations are shown approximately to protect privacy.</p>
  </section>
  @endif

  {{-- photos (documents stay in the signed-in console) --}}
  @if ($images)
  <section class="card">
    <h2>Photos</h2>
    <div class="tk-gallery">
      @foreach ($images as $f)
        <a href="{{ $f['url'] }}" target="_blank" rel="noopener" class="tk-photo">
          <img src="{{ $f['url'] }}" alt="{{ $f['name'] }}" loading="lazy">
          <span>@if ($f['proof'])<b>Proof of delivery</b>@else{{ $f['name'] }}@endif</span>
        </a>
      @endforeach
    </div>
  </section>
  @endif

  {{-- activity --}}
  <section class="card">
    <h2>Activity</h2>
    <ol class="tk-log">
      @foreach ($activity as $i => $a)
        <li class="{{ $i === 0 ? 'latest' : '' }}">
          <div class="tk-log-head"><strong>{{ $a['status'] }}</strong>@if ($a['at'])<time>{{ $a['at'] }}</time>@endif</div>
          @if ($a['details'])<p>{{ $a['details'] }}</p>@endif
          @if ($a['where'])<small>{{ $a['where'] }}</small>@endif
        </li>
      @endforeach
    </ol>
  </section>

  <section class="card share">
    <h2>Share this tracking page</h2>
    <div class="share-row">
      <a class="btn btn-wa" href="https://wa.me/?text={{ rawurlencode($share) }}" target="_blank" rel="noopener">WhatsApp</a>
      <button class="btn btn-ghost" type="button" id="copy" data-url="{{ $url }}">Copy link</button>
      <button class="btn btn-ghost" type="button" id="native" hidden>More…</button>
    </div>
  </section>
</main>

<footer class="foot">
  <a href="https://travo.ng/">travo.ng</a> · <a href="{{ $whatsapp }}" target="_blank" rel="noopener">WhatsApp support</a>
  <p>This page updates as your order moves. Refresh to see the latest.</p>
</footer>

<script>
(function () {
  function copy(text, el, label) {
    var done = function () { var o = el.getAttribute('data-label') || el.innerHTML; el.setAttribute('data-label', o); el.classList.add('ok'); setTimeout(function () { el.classList.remove('ok'); }, 1600); };
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () { prompt('Copy', text); });
    else prompt('Copy', text);
  }
  document.querySelectorAll('[data-copy]').forEach(function (b) { b.addEventListener('click', function () { copy(b.getAttribute('data-copy'), b); }); });
  var c = document.getElementById('copy'), n = document.getElementById('native');
  c && c.addEventListener('click', function () {
    var u = c.getAttribute('data-url');
    var done = function () { c.textContent = 'Link copied'; setTimeout(function () { c.textContent = 'Copy link'; }, 2000); };
    if (navigator.clipboard) navigator.clipboard.writeText(u).then(done, function () { prompt('Copy this link', u); });
    else prompt('Copy this link', u);
  });
  if (navigator.share && n) {
    n.hidden = false;
    n.addEventListener('click', function () { navigator.share({ title: document.title, url: c.getAttribute('data-url') }).catch(function () {}); });
  }
})();
</script>
</body>
</html>
