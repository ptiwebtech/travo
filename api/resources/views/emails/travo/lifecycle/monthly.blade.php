@php $monthName = now('Africa/Lagos')->subDays(15)->format('F'); @endphp
@if ($final)
  @include('emails.travo.lifecycle._hero', ['eyebrow' => 'One last note', 'title' => 'Should we stop emailing you?'])
  <tr><td class="px" style="padding:18px 36px 6px">
    <p style="margin:0;font-size:16px;line-height:1.7;color:#334155">Hi {{ $first ?: 'there' }},</p>
    <p style="margin:12px 0 0;font-size:16px;line-height:1.7;color:#334155">It's been a year of updates from us, and we don't want to fill your inbox. This is our last monthly email unless you book with us again. Your account stays open, and a quote is always free and takes under a minute.</p>
    @include('emails.travo.lifecycle._button', ['href' => $cta, 'label' => 'Get a free quote'])
  </td></tr>
  <tr><td class="px" style="padding:28px 36px 4px">
    @include('emails.travo.lifecycle._h2', ['kicker' => 'The last 30 days', 'text' => 'What\'s been happening on Travo'])
    @include('emails.travo.lifecycle._stats')
  </td></tr>
@else
  @include('emails.travo.lifecycle._hero', ['eyebrow' => 'Monthly update', 'title' => 'Travo in ' . $monthName])
  <tr><td class="px" style="padding:18px 36px 6px">
    <p style="margin:0;font-size:16px;line-height:1.7;color:#334155">Hi {{ $first ?: 'there' }},</p>
    <p style="margin:12px 0 0;font-size:16px;line-height:1.7;color:#334155">Here's what happened on Travo over the last 30 days: how many orders came through, which services people booked most, and a few real examples of what they paid.</p>
  </td></tr>
  <tr><td class="px" style="padding:24px 36px 4px">@include('emails.travo.lifecycle._stats')</td></tr>
  @include('emails.travo.lifecycle._spotlight')
  @if (!empty($news))
  <tr><td class="px" style="padding:6px 36px 4px">
    @include('emails.travo.lifecycle._h2', ['kicker' => 'New on Travo', 'text' => 'What we\'ve added'])
    <p style="margin:-6px 0 0;font-size:15px;line-height:1.65;color:#334155">{{ $news }}</p>
  </td></tr>
  @endif
  <tr><td class="px" style="padding:12px 36px 4px">
    <p style="margin:0;font-size:16px;line-height:1.7;color:#334155">Whatever you need moved next, you can have a price in under a minute.</p>
    @include('emails.travo.lifecycle._button', ['href' => $cta, 'label' => 'Get a free quote', 'm' => '18px 0 18px'])
  </td></tr>
@endif
