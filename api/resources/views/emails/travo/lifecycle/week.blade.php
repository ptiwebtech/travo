@include('emails.travo.lifecycle._hero', ['eyebrow' => 'A closer look', 'title' => 'How people and businesses get things moved with Travo'])

<tr><td class="px" style="padding:18px 36px 6px">
  <p style="margin:0;font-size:16px;line-height:1.7;color:#334155">Hi {{ $first ?: 'there' }},</p>
  <p style="margin:12px 0 0;font-size:16px;line-height:1.7;color:#334155">It's been a week since you joined, so here's a closer look at what people book most on Travo, and what you get when you do.</p>
</td></tr>

@foreach ([
  ['📦', 'Parcels & documents', 'Same-day across Lagos, next-day nationwide.', [
      'A price before you book, based on size and distance',
      'Optional extras: white-glove handling, fragile-item care, packaging, temperature control and insurance cover (up to ₦500,000)',
      'A shipping label with QR code and a live tracking link for the recipient',
      'Photos and proof of delivery kept on your order',
  ], 'Quote a parcel', 'parcels'],
  ['🚌', 'Travel', 'Intercity tickets, airport pickups and drivers.', [
      'Bus and train seats with Nigeria\'s intercity operators, without the queue at the park',
      'Airport meet-and-greet for you, your family or your guests',
      'A car with a driver by the hour, the day or the occasion',
  ], 'Plan a trip', 'travel'],
  ['🏢', 'Business logistics', 'The jobs that keep a business running.', [
      'Vans and trucks for stock and equipment, and vehicles on daily, weekly or monthly charter',
      'Scheduled diesel, gas and water deliveries for offices, generators and sites',
      'Heavy machinery on lease, containers in and out of Nigeria, skip hire and waste collection',
      'Shareable quotes and tracking pages for your own customers',
  ], 'Quote for your business', 'business'],
] as $d)
<tr><td class="px" style="padding:26px 36px 0">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e6ebf2;border-radius:14px">
    <tr><td style="padding:22px 22px 6px">
      <div style="font-size:26px;line-height:1">{{ $d[0] }}</div>
      <h2 style="margin:10px 0 2px;font-size:21px;color:#0b1220">{{ $d[1] }}</h2>
      <p style="margin:0;font-size:15px;color:#64748b">{{ $d[2] }}</p>
    </td></tr>
    <tr><td style="padding:8px 22px 4px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:15px;line-height:1.55;color:#334155">
        @foreach ($d[3] as $pt)<tr><td width="22" valign="top" style="padding:0 0 9px;color:#2563eb;font-weight:bold">•</td><td style="padding:0 0 9px">{{ $pt }}</td></tr>@endforeach
      </table>
    </td></tr>
    <tr><td style="padding:2px 22px 20px"><a href="{{ $linkFor($d[5]) }}" style="font-size:15px;font-weight:bold;color:#2563eb;text-decoration:none">{{ $d[4] }} &rarr;</a></td></tr>
  </table>
</td></tr>
@endforeach

<tr><td class="px" style="padding:32px 36px 4px">
  @include('emails.travo.lifecycle._h2', ['kicker' => 'Why Travo', 'text' => 'Better prices, better technology, real people'])
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    @foreach ([
      ['Some of the most competitive prices around', 'A network of over 1,000 partner companies competes for your job, which keeps our prices among the sharpest in Nigeria. Compare us on your next booking.'],
      ['Technology built for Nigeria', 'Our own in-house team builds Travo: instant pricing, live tracking, shareable quotes and tracking pages, and apps for iPhone and Android.'],
      ['People who pick up', 'When something needs a human, you reach one on WhatsApp or by replying to any of our emails.'],
    ] as $w)
    <tr><td style="padding:0 0 16px;border-left:3px solid #2563eb;padding-left:14px"><strong style="font-size:16px;color:#0f172a">{{ $w[0] }}</strong><p style="margin:4px 0 0;font-size:15px;line-height:1.6;color:#475569">{{ $w[1] }}</p></td></tr>
    <tr><td style="height:10px;font-size:0;line-height:0">&nbsp;</td></tr>
    @endforeach
  </table>
  @include('emails.travo.lifecycle._button', ['href' => $cta, 'label' => 'Get a free quote', 'm' => '8px 0 18px'])
</td></tr>
