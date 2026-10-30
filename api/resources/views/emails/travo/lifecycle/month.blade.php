@include('emails.travo.lifecycle._hero', ['eyebrow' => 'Your first month', 'title' => 'Here\'s what you\'ve been missing on Travo'])

<tr><td class="px" style="padding:18px 36px 6px">
  <p style="margin:0;font-size:16px;line-height:1.7;color:#334155">Hi {{ $first ?: 'there' }},</p>
  <p style="margin:12px 0 0;font-size:16px;line-height:1.7;color:#334155">It's been a month since you joined Travo. While you've been away, people and businesses across Nigeria have been booking parcels, trips, trucks and deliveries every day. Here's the last 30 days at a glance.</p>
</td></tr>

<tr><td class="px" style="padding:24px 36px 4px">@include('emails.travo.lifecycle._stats')</td></tr>

@include('emails.travo.lifecycle._spotlight')

<tr><td class="px" style="padding:8px 36px 4px">
  <p style="margin:0;font-size:16px;line-height:1.7;color:#334155">Your account is ready whenever you are. Getting a price takes under a minute and costs nothing.</p>
  @include('emails.travo.lifecycle._button', ['href' => $cta, 'label' => 'Get a free quote', 'm' => '18px 0 18px'])
</td></tr>
