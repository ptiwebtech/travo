<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Travo invites | Travo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
@include('quote-share-styles')
<style>.muted{color:#9fb0cc;margin:0 0 18px}form{margin:0}</style>
</head>
<body>
<header class="top">
  <a href="https://travo.ng/" class="logo"><img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo.ng" width="118" height="32"></a>
</header>
<main class="wrap">
  <section class="card cta">
    <p class="eyebrow-l">Travo invites</p>
    @if (!$valid)
      <h2>This link isn't valid.</h2>
      <p class="muted">It may be incomplete. Reply to the invite email and we'll make sure you don't get any more.</p>
    @elseif ($done)
      <h2>Done. <span>No more Travo invites.</span></h2>
      <p class="muted">{{ $email }} won't get invites from anyone on Travo again. If you ever want to try Travo, you can still sign up at travo.ng.</p>
    @else
      <h2>Stop getting Travo invites?</h2>
      <p class="muted">Nobody on Travo will be able to invite {{ $email }} again.</p>
      <form method="post" action=""><button class="btn btn-lg" type="submit">Don't invite me again</button></form>
    @endif
  </section>
</main>
</body>
</html>
