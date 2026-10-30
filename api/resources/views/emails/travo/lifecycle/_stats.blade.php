{{-- platform numbers for the last 30 days --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:14px">
  <tr>
    <td class="stat" width="33%" valign="top" style="padding:18px 18px">
      <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b">Orders placed</div>
      <div style="margin-top:6px;font-size:28px;font-weight:bold;color:#0f172a">{{ number_format($stats['orders']) }}</div>
      @if ($stats['ordersVsPrev'] !== null && $stats['ordersVsPrev'] > 0)<div style="margin-top:2px;font-size:13px;color:#16a34a">▲ {{ $stats['ordersVsPrev'] }}% on the month before</div>@endif
    </td>
    <td class="stat" width="33%" valign="top" style="padding:18px 18px;border-left:1px solid #e2e8f0">
      <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b">New members</div>
      <div style="margin-top:6px;font-size:28px;font-weight:bold;color:#0f172a">+{{ number_format($stats['newMembers']) }}</div>
      @if ($stats['membersGrowth'])<div style="margin-top:2px;font-size:13px;color:#16a34a">▲ {{ $stats['membersGrowth'] }}% growth</div>@endif
    </td>
    <td class="stat" width="33%" valign="top" style="padding:18px 18px;border-left:1px solid #e2e8f0">
      <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b">Members</div>
      <div style="margin-top:6px;font-size:28px;font-weight:bold;color:#0f172a">{{ number_format($stats['members']) }}</div>
      <div style="margin-top:2px;font-size:13px;color:#64748b">and counting</div>
    </td>
  </tr>
</table>
<p style="margin:8px 0 0;font-size:12px;color:#94a3b8">{{ $stats['period'] }}</p>

@if ($stats['top'])
<p style="margin:22px 0 8px;font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:#64748b;font-weight:bold">Most booked</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:15px">
  @foreach ($stats['top'] as $i => $t)
  <tr><td style="padding:8px 0;border-top:1px solid #eef2f7;color:#0f172a"><span style="color:#94a3b8">{{ $i + 1 }}.</span> {{ $t['name'] }}</td><td align="right" style="padding:8px 0;border-top:1px solid #eef2f7;color:#64748b;white-space:nowrap">{{ number_format($t['n']) }} orders</td></tr>
  @endforeach
</table>
@endif

@if ($stats['samples'])
<p style="margin:22px 0 8px;font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:#64748b;font-weight:bold">Recent orders on Travo</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">
  @foreach ($stats['samples'] as $o)
  <tr>
    <td style="padding:10px 0;border-top:1px solid #eef2f7;line-height:1.45"><strong style="color:#0f172a">{{ $o['type'] }}</strong><br><span style="color:#64748b">{{ $o['route'] }} · {{ $o['when'] }}</span></td>
    <td align="right" valign="top" style="padding:10px 0;border-top:1px solid #eef2f7;font-weight:bold;color:#0f172a;white-space:nowrap">{{ $o['price'] }}</td>
  </tr>
  @endforeach
</table>
<p style="margin:8px 0 0;font-size:12px;color:#94a3b8">Areas only; no customer details are shared.</p>
@endif
