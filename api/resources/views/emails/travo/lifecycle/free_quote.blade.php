@include('emails.travo.lifecycle._hero', ['eyebrow' => 'Quotes are free', 'title' => 'Get a price in under a minute. It\'s free, every time.'])

<tr><td class="px" style="padding:18px 36px 6px">
  <p style="margin:0;font-size:16px;line-height:1.7;color:#334155">Hi {{ $first ?: 'there' }},</p>
  <p style="margin:12px 0 0;font-size:16px;line-height:1.7;color:#334155">You don't need to be ready to book to use Travo. Pick a service, add where it's going, and you'll see a full price straight away. There's no card to enter and nothing to commit to. Quote as often as you like, for yourself or for a client.</p>
  @include('emails.travo.lifecycle._button', ['href' => $cta, 'label' => 'Get a free quote now'])
</td></tr>

<tr><td class="px" style="padding:30px 36px 4px">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    @foreach ([
      ['🔗', 'Share quotes with customers and clients', 'Every quote has its own page with the price, route and map. Send it on WhatsApp or by email so a customer can see exactly what they\'re paying for.'],
      ['📍', 'Tracked along the way', 'Once you book, your order gets a live tracking page: each step, with the time it happened. Share it with whoever is waiting.'],
      ['🧾', 'Documentary evidence, built in', 'Quotes, payment references, shipping labels, timestamps, photos and proof of delivery stay on your order, ready for your records or your client.'],
      ['💳', 'Pay only when you\'re happy', 'Pay by bank transfer with your own reference. We confirm receipt by email and get moving.'],
    ] as $b)
    <tr><td width="46" valign="top" style="padding:0 0 18px;font-size:24px;line-height:1">{{ $b[0] }}</td>
    <td valign="top" style="padding:0 0 18px"><strong style="font-size:16px;color:#0f172a">{{ $b[1] }}</strong><p style="margin:4px 0 0;font-size:15px;line-height:1.6;color:#475569">{{ $b[2] }}</p></td></tr>
    @endforeach
  </table>
</td></tr>

<tr><td class="px" style="padding:6px 36px 4px">
  @include('emails.travo.lifecycle._h2', ['kicker' => 'What you can quote for', 'text' => 'For you, and for your business'])
  <p style="margin:0 0 6px;font-size:13px;font-weight:bold;color:#64748b;text-transform:uppercase;letter-spacing:.06em">Individuals</p>
  <p style="margin:0 0 16px;font-size:15px;line-height:1.9;color:#334155">@foreach ($individual as $sv)<a href="{{ $linkFor(\Illuminate\Support\Str::slug($sv[1])) }}" style="display:inline-block;margin:0 6px 6px 0;padding:4px 12px;border-radius:999px;background:#f1f5f9;color:#0f172a;text-decoration:none;font-size:14px">{{ $sv[0] }} {{ $sv[1] }}</a>@endforeach</p>
  <p style="margin:0 0 6px;font-size:13px;font-weight:bold;color:#64748b;text-transform:uppercase;letter-spacing:.06em">Businesses</p>
  <p style="margin:0 0 6px;font-size:15px;line-height:1.9;color:#334155">@foreach ($business as $sv)<a href="{{ $linkFor(\Illuminate\Support\Str::slug($sv[1])) }}" style="display:inline-block;margin:0 6px 6px 0;padding:4px 12px;border-radius:999px;background:#eff6ff;color:#0f172a;text-decoration:none;font-size:14px">{{ $sv[0] }} {{ $sv[1] }}</a>@endforeach</p>
  @include('emails.travo.lifecycle._button', ['href' => $cta, 'label' => 'Try it: get a free quote', 'm' => '16px 0 18px'])
</td></tr>
