@php
    $ios     = 'https://apps.apple.com/us/app/travo-ng/id6769794240?pt=quote&ct=quote-page&mt=8';
    $android = 'https://play.google.com/store/apps/details?id=com.travong.app&referrer=utm_source%3Dquote%26utm_medium%3Dshare%26utm_campaign%3Dquote-page';
    $signup  = 'https://travo.ng/onboard?utm_source=quote&utm_medium=share&utm_campaign=quote-page';
    $share   = 'Here is my ' . $orderType . ' quote on Travo' . ($price ? ' (' . $price . ')' : '') . ': ' . $url;
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $ogTitle }} | Travo</title>
<meta name="description" content="{{ $ogText }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Travo.ng">
<meta property="og:title" content="{{ $ogTitle }}">
<meta property="og:description" content="{{ $ogText }}">
<meta property="og:image" content="https://travo.ng/directory/assets/og-image.png">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#111a2b">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
@include('quote-share-styles')
</head>
<body>
<header class="top">
  <a href="https://travo.ng/" class="logo"><img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo.ng" width="118" height="32"></a>
  <a href="{{ $signup }}" class="btn btn-sm">Get your own quote</a>
</header>

<main class="wrap">
  <section class="card quote">
    <div class="eyebrow">
      <span>Shared quote</span>
      <span class="pill {{ $closed ? 'pill-off' : '' }}">{{ $status }}</span>
    </div>
    <h1>{{ $orderType }}</h1>
    @if ($service)<p class="service">{{ $service }}</p>@endif

    <div class="price-row">
      @if ($price)
        <div class="price">{{ $price }}</div>
        <div class="price-note">Total quoted price</div>
      @else
        <div class="price price-sm">Price confirmed on booking</div>
      @endif
    </div>
    @if ($closed)
      <p class="notice">This order is {{ strtolower($status) }}, so this price may no longer be available. Get a fresh quote in seconds below.</p>
    @endif

    <ol class="route">
      @if ($pickup)
      <li><span class="dot dot-a">A</span><div><small>From</small><strong>{{ $pickup['label'] }}</strong></div></li>
      @endif
      @if ($dropoff)
      <li><span class="dot dot-b">B</span><div><small>To</small><strong>{{ $dropoff['label'] }}</strong></div></li>
      @endif
    </ol>

    <dl class="facts">
      @if ($distanceKm)<div><dt>Distance</dt><dd>{{ $distanceKm }} km</dd></div>@endif
      @if ($minutes)<div><dt>Est. travel time</dt><dd>{{ $minutes >= 60 ? floor($minutes / 60) . ' h ' . ($minutes % 60) . ' min' : $minutes . ' min' }}</dd></div>@endif
      @if ($created)<div><dt>Quoted on</dt><dd>{{ $created }}</dd></div>@endif
      @if ($scheduled)<div><dt>Scheduled</dt><dd>{{ $scheduled }}</dd></div>@endif
      <div><dt>Reference</dt><dd>{{ $ref }}</dd></div>
    </dl>

    @if ($items)
      <div class="items"><h2>Items</h2><ul>@foreach ($items as $it)<li>{{ $it }}</li>@endforeach</ul></div>
    @endif

    @if ($lines)
      <div class="breakdown">
        <h2>Price breakdown</h2>
        <table>
          @foreach ($lines as $l)<tr><td>{{ $l['label'] }}</td><td>{{ $l['amount'] }}</td></tr>@endforeach
          @if ($price)<tr class="total"><td>Total</td><td>{{ $price }}</td></tr>@endif
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

  {{-- travo-card --}}@php $tvPay = !$closed && class_exists(\App\Support\TravoCard::class) && \App\Support\TravoCard::ready() && rescue(fn () => (\App\Http\Controllers\OrderFlowController::state(\Fleetbase\FleetOps\Models\Order::where('public_id', $ref)->firstOrFail())['next']['key'] ?? null) === 'paid', false, false); @endphp
  @if ($tvPay)
  <section class="card" style="text-align:center">
    <h2 style="margin-bottom:6px">Ready to book?</h2>
    <p style="color:#9fb0cc;margin:0 0 14px">Pay {{ $price }} securely by card and your booking is confirmed straight away.</p>
    <a class="btn btn-lg" href="{{ str_replace('/quote/', '/pay/', $url) }}" style="display:block;background:#16a34a;border-color:#16a34a">&#128274; Pay {{ $price }} by card</a>
    <p style="color:#9fb0cc;font-size:12px;margin:10px 0 0">Verve, Mastercard or Visa · secured by Quickteller (Interswitch) · bank transfer details on the next page</p>
  </section>
  @endif
  <section class="card share">
    <h2>Share this quote</h2>
    <div class="share-row">
      <a class="btn btn-wa" href="https://wa.me/?text={{ rawurlencode($share) }}" target="_blank" rel="noopener">WhatsApp</a>
      <button class="btn btn-ghost" type="button" id="copy" data-url="{{ $url }}">Copy link</button>
      <button class="btn btn-ghost" type="button" id="native" hidden>More…</button>
    </div>
  </section>

  <section class="card cta">
    <p class="eyebrow-l">Free account · no subscription</p>
    <h2>Need something moved? <span>Get an instant quote.</span></h2>
    <ul class="ticks">
      <li>Same-day parcel delivery across Lagos</li>
      <li>Bus and train tickets, airport pickups</li>
      <li>Cars with drivers, vans and trucks for haulage</li>
      <li>Track every order live in the app</li>
    </ul>
    <div class="cta-row">
      <a href="{{ $signup }}" class="btn btn-lg">Create a free account</a>
      <a href="https://travo.ng/auth" class="link">Already have an account? Sign in</a>
    </div>
    <div class="stores">
      <a class="store" href="{{ $ios }}" rel="noopener"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M16.4 12.6c0-2.6 2.1-3.8 2.2-3.9-1.2-1.8-3.1-2-3.7-2-1.6-.2-3.1.9-3.9.9s-2-.9-3.4-.9c-1.7 0-3.3 1-4.2 2.6-1.8 3.1-.5 7.7 1.3 10.2.9 1.2 1.9 2.6 3.2 2.6 1.3-.1 1.8-.8 3.3-.8s2 .8 3.4.8 2.3-1.3 3.1-2.5c1-1.4 1.4-2.8 1.4-2.9-.1 0-2.7-1-2.7-4.1ZM13.9 5c.7-.9 1.2-2 1.1-3.2-1 0-2.3.7-3 1.6-.7.8-1.3 2-1.1 3.1 1.1.1 2.3-.6 3-1.5Z"/></svg><span><small>Download on the</small>App Store</span></a>
      <a class="store" href="{{ $android }}" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M3.6 2.3 13.4 12l-9.8 9.7c-.3-.2-.6-.6-.6-1.1V3.4c0-.5.3-.9.6-1.1Zm11 8.5 2.6-2.6 3.3 1.9c1 .6 1 1.6 0 2.2l-3.3 1.9-2.6-2.6v-.8Zm-.7-.7L5 1.2l9.4 5.4-1.5 1.5v2Zm0 1.8v2l1.5 1.5L5 22.8l8.9-8.9Z"/></svg><span><small>Get it on</small>Google Play</span></a>
    </div>
  </section>
</main>

<footer class="foot">
  <a href="https://travo.ng/">travo.ng</a> · <a href="https://travo.ng/directory">Directory</a> · <a href="https://travo.ng/vendor-signup">Register your business</a>
  <p>Prices are estimates from the moment the quote was made and are confirmed when the order is booked.</p>
</footer>

<script>
(function () {
  var c = document.getElementById('copy'), n = document.getElementById('native');
  c && c.addEventListener('click', function () {
    var u = c.getAttribute('data-url'), done = function () { c.textContent = 'Link copied'; setTimeout(function () { c.textContent = 'Copy link'; }, 2000); };
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
