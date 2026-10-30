<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Email preferences | Travo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
@include('quote-share-styles')
<style>.state{display:none}.state.on{display:block}.muted{color:#9fb0cc;margin:0 0 18px}form{margin:0}</style>
</head>
<body>
<header class="top">
  <a href="https://travo.ng/" class="logo"><img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo.ng" width="118" height="32"></a>
</header>
<main class="wrap">
  <section class="card cta">
    <p class="eyebrow-l">Email preferences</p>
    @if (!$valid)
      <h2>This link isn't valid.</h2>
      <p class="muted">It may be incomplete. Use the unsubscribe link in the latest Travo email, or reply to that email and we'll take you off the list.</p>
    @else
      {{-- before: shown while the opt-out is sent, or as a button when scripts are off --}}
      <div class="state {{ !$done && !$back ? 'on' : '' }}" id="ask">
        <h2>Stop Travo update emails?</h2>
        <p class="muted">You'll stop getting our welcome, tips and monthly update emails. Emails about your own orders (quotes, payments, tracking) still arrive.</p>
        <form method="post" action="" id="f-out"><button class="btn btn-lg" type="submit">Unsubscribe</button></form>
      </div>
      <div class="state {{ $done ? 'on' : '' }}" id="out">
        <h2>You're unsubscribed. <span>No more update emails.</span></h2>
        <p class="muted">Emails about your own orders (quotes, payments and tracking) still arrive. Changed your mind?</p>
        <form method="post" action=""><input type="hidden" name="resubscribe" value="1"><button class="btn btn-ghost" type="submit">Keep getting Travo updates</button></form>
      </div>
      <div class="state {{ $back ? 'on' : '' }}" id="in">
        <h2>Welcome back. <span>You're subscribed again.</span></h2>
        <p class="muted">You'll get Travo updates again. You can unsubscribe at any time from any of our emails.</p>
        <a class="btn" href="https://travo.ng/auth">Go to Travo</a>
      </div>
    @endif
  </section>
</main>
@if ($valid && !$done && !$back)
<script>
(function () {
  // one click for people: opt out as soon as the page opens (robots that pre-open links don't run this)
  var body = new URLSearchParams(); body.append('ajax', '1');
  fetch(location.pathname, { method: 'POST', body: body, headers: { 'Accept': 'application/json' }, credentials: 'omit' })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
    .then(function () { document.getElementById('ask').classList.remove('on'); document.getElementById('out').classList.add('on'); })
    .catch(function () {});
})();
</script>
@endif
</body>
</html>
