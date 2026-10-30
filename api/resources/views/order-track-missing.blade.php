<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Tracking link not found | Travo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
@include('quote-share-styles')
</head>
<body>
<header class="top">
  <a href="https://travo.ng/" class="logo"><img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo.ng" width="118" height="32"></a>
</header>
<main class="wrap">
  <section class="card cta">
    <p class="eyebrow-l">Order tracking</p>
    @if (!empty($broken))
      <h2>We couldn't load this order right now. <span>Please try again shortly.</span></h2>
      <p style="color:#9fb0cc;margin:0 0 18px">If it keeps happening, message us on WhatsApp with your order reference and we'll update you directly.</p>
    @else
      <h2>This tracking link isn't valid. <span>Check the link and try again.</span></h2>
      <p style="color:#9fb0cc;margin:0 0 18px">The link may be incomplete. Open it again from your Travo email, or message us on WhatsApp with your order reference.</p>
    @endif
    <div class="cta-row">
      <a href="https://wa.me/2349163352137" class="btn btn-lg btn-wa">Message us on WhatsApp</a>
      <a href="https://travo.ng/" class="link">Go to travo.ng</a>
    </div>
  </section>
</main>
</body>
</html>
