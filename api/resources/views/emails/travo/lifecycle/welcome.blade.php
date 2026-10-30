@include('emails.travo.lifecycle._hero', ['eyebrow' => 'Welcome to Travo', 'title' => 'Everything you need moved, booked in one place.'])

<tr><td class="px" style="padding:18px 36px 6px">
  <p style="margin:0;font-size:16px;line-height:1.7;color:#334155">Hi {{ $first ?: 'there' }},</p>
  <p style="margin:12px 0 0;font-size:16px;line-height:1.7;color:#334155">Welcome to Travo. You now have one account for logistics, travel and everyday services across Nigeria: an instant price, a vetted partner, secure payment and live tracking, whether it's a same-day document run across Lagos or a container leaving Apapa.</p>
  @include('emails.travo.lifecycle._button', ['href' => $cta, 'label' => 'Get your first instant quote'])
</td></tr>

{{-- proof strip --}}
<tr><td class="px" style="padding:26px 36px 4px">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0b1220;border-radius:14px"><tr>
    <td class="stat" width="33%" style="padding:18px 16px;text-align:center"><div style="font-size:24px;font-weight:bold;color:#ffffff">1,000+</div><div style="font-size:12px;color:#93a7c7;margin-top:2px">partner companies</div></td>
    <td class="stat" width="33%" style="padding:18px 16px;text-align:center;border-left:1px solid #1e2a40"><div style="font-size:24px;font-weight:bold;color:#ffffff">Nationwide</div><div style="font-size:12px;color:#93a7c7;margin-top:2px">same-day in Lagos</div></td>
    <td class="stat" width="33%" style="padding:18px 16px;text-align:center;border-left:1px solid #1e2a40"><div style="font-size:24px;font-weight:bold;color:#ffffff">24/7</div><div style="font-size:12px;color:#93a7c7;margin-top:2px">booking on web &amp; app</div></td>
  </tr></table>
</td></tr>

<tr><td class="px" style="padding:30px 36px 4px">
  @include('emails.travo.lifecycle._h2', ['kicker' => 'For you and your family', 'text' => 'Everyday things, handled'])
  @include('emails.travo.lifecycle._services', ['list' => $individual])
</td></tr>

<tr><td class="px" style="padding:18px 36px 4px">
  @include('emails.travo.lifecycle._h2', ['kicker' => 'For your business', 'text' => 'A logistics department, on demand'])
  <p style="margin:-6px 0 16px;font-size:15px;line-height:1.65;color:#334155">Offices, shops, sites and SMEs use Travo for the jobs that keep the business moving, without hiring drivers or keeping vehicles on standby.</p>
  @include('emails.travo.lifecycle._services', ['list' => $business])
</td></tr>

<tr><td class="px" style="padding:18px 36px 4px">
  @include('emails.travo.lifecycle._h2', ['kicker' => 'How it works', 'text' => 'Booked in three steps'])
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:15px;line-height:1.6;color:#334155">
    @foreach ([['1', 'Get an instant price', 'Choose a service, add your pickup and drop-off, and see the price straight away. Quotes are free.'], ['2', 'Pay securely', 'Pay by bank transfer with your own payment reference, so every naira is matched to your order.'], ['3', 'Track it end to end', 'Follow every step on your tracking page, with times, photos and proof of delivery.']] as $st)
    <tr><td width="44" valign="top" style="padding:0 0 14px"><div style="width:30px;height:30px;line-height:30px;border-radius:50%;background:#2563eb;color:#ffffff;text-align:center;font-weight:bold;font-size:14px">{{ $st[0] }}</div></td>
    <td valign="top" style="padding:3px 0 14px"><strong style="color:#0f172a">{{ $st[1] }}</strong><br>{{ $st[2] }}</td></tr>
    @endforeach
  </table>
</td></tr>

<tr><td class="px" style="padding:10px 36px 4px">
  @include('emails.travo.lifecycle._h2', ['kicker' => 'Why Travo', 'text' => 'The partner you\'ll keep coming back to'])
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:15px;line-height:1.6;color:#334155">
    @foreach ([['Competitive, transparent prices', 'You see the full price before you book: no haggling, no surprises.'], ['Tracked from start to finish', 'A live tracking page you can share with whoever is waiting.'], ['A paper trail you can rely on', 'Quotes, labels, timestamps, photos and proof of delivery, all kept on your order.'], ['People who pick up', 'Real support on WhatsApp and email when you need it.']] as $w)
    <tr><td valign="top" width="26" style="padding:0 0 12px;color:#16a34a;font-weight:bold">✓</td><td style="padding:0 0 12px"><strong style="color:#0f172a">{{ $w[0] }}.</strong> {{ $w[1] }}</td></tr>
    @endforeach
  </table>
  @include('emails.travo.lifecycle._button', ['href' => $cta, 'label' => 'Get a free quote', 'm' => '10px 0 18px'])
</td></tr>
