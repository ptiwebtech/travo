<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Job offer | Travo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
@include('quote-share-styles')
<style>
.muted{color:#9fb0cc;margin:0 0 18px}form{margin:0}
.vo-price{font-family:'Space Grotesk',Inter,sans-serif;font-size:40px;font-weight:700;color:#4ade80;margin:4px 0 6px}
.vo-rows{width:100%;border-collapse:collapse;margin:18px 0 6px}
.vo-rows td{padding:10px 0;border-bottom:1px solid rgba(159,176,204,.18);vertical-align:top;font-size:15px}
.vo-rows td:first-child{width:110px;color:#9fb0cc;font-size:13px}
.vo-acts{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}
.vo-acts button{flex:1 1 200px}
.btn-quiet{background:transparent!important;border:1px solid rgba(159,176,204,.4)!important;color:#dbe4f3!important}
.vo-badge{display:inline-block;background:rgba(74,222,128,.14);color:#4ade80;border-radius:999px;padding:6px 12px;font-weight:600;font-size:14px;margin:6px 0 12px}
.vo-small{font-size:13px;color:#9fb0cc;margin:14px 0 0;line-height:1.6}
.vo-small a{color:#9fb0cc}
</style>
</head>
<body>
<header class="top">
  <a href="https://travo.ng/" class="logo"><img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo.ng" width="118" height="32"></a>
</header>
<main class="wrap">
  <section class="card cta">
    <p class="eyebrow-l">Travo job offer</p>
  @if (!$valid)
    <h2>This link isn't valid.</h2>
    <p class="muted">It may be incomplete. Reply to the offer email and we'll help.</p>
  @elseif ($mode === 'stop')
    @if ($optedOut)
      <h2>Done. <span>No more job offers.</span></h2>
      <p class="muted">{{ $vendor }} has been taken off our vendor lists and won't get Travo job offers again. If this was a mistake, reply to any offer email or message us on WhatsApp and we'll add you back.</p>
    @else
      <h2>Stop getting job offers?</h2>
      <p class="muted">{{ $vendor }} will be taken off every Travo service's vendor list and won't get job offers any more.</p>
      <form method="post" action=""><input type="hidden" name="do" value="stop"><button class="btn btn-lg" type="submit">Stop sending me job offers</button></form>
    @endif
  @else
    @if ($offer->status === 'accepted')
      <h2>You've accepted this job.</h2>
      <span class="vo-badge">You were #{{ $rank }} to accept</span>
      <p class="muted">Our team will contact you on your registered phone number if the job is assigned to you. Keep your phone close.</p>
    @elseif (!$open)
      <h2>This offer has closed.</h2>
      <p class="muted">The job has been assigned, cancelled, or the time to accept has passed. Accept the next offer quickly to be first in line.</p>
    @elseif ($offer->status === 'declined')
      <h2>No problem.</h2>
      <p class="muted">We've noted you can't do this one. Changed your mind? You can still accept below while the offer is open.</p>
    @else
      <h2>Can you do this job?</h2>
      <p class="muted">The first vendors to accept are first in line.@if ($accepted) {{ $accepted }} {{ $accepted === 1 ? 'vendor has' : 'vendors have' }} already accepted.@endif</p>
    @endif

    <p class="vo-small" style="margin:0">Your price</p>
    <div class="vo-price">{{ $price }}</div>
    <table class="vo-rows">
      <tr><td>Job</td><td>{{ trim(($job['orderType'] ?? '') . (!empty($job['service']) && $job['service'] !== ($job['orderType'] ?? '') ? ' · ' . $job['service'] : '')) }}</td></tr>
      <tr><td>Pickup</td><td>{{ $job['pickup'] ?? '' }}</td></tr>
      @if (!empty($job['dropoff']))<tr><td>Drop-off</td><td>{{ $job['dropoff'] }}</td></tr>@endif
      @if (!empty($job['distanceKm']))<tr><td>Distance</td><td>{{ $job['distanceKm'] }} km</td></tr>@endif
      @if (!empty($job['items']))<tr><td>Items</td><td>{{ implode(', ', $job['items']) }}</td></tr>@endif
      @if (!empty($job['when']))<tr><td>When</td><td>{{ $job['when'] }}</td></tr>@endif
      @if ($open && $expires)<tr><td>Offer closes</td><td>{{ $expires->copy()->timezone('Africa/Lagos')->format('D j M, g:ia') }}</td></tr>@endif
      <tr><td>Job ref</td><td>{{ $job['ref'] ?? '' }}</td></tr>
    </table>
    <p class="vo-small">Exact addresses and contact details are shared with the vendor the job is assigned to.</p>

    @if ($open && $offer->status !== 'accepted')
      <form method="post" action="" class="vo-acts">
        <button class="btn btn-lg" type="submit" name="do" value="accept">Yes, I can do this job for {{ $price }}</button>
        @if ($offer->status !== 'declined')<button class="btn btn-lg btn-quiet" type="submit" name="do" value="decline">Not this time</button>@endif
      </form>
    @endif
    <p class="vo-small">Questions? <a href="{{ $whatsapp }}">Message us on WhatsApp</a>. · <a href="{{ $stopUrl }}">Stop sending me job offers</a></p>
  @endif
  </section>
</main>
</body>
</html>
