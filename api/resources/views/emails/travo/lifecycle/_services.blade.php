{{-- two-column service grid: $list = [[emoji, name, line], ...] --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
  @foreach (array_chunk($list, 2) as $pair)
  <tr>
    @foreach ($pair as $sv)
    <td class="col" width="50%" valign="top" style="padding:0 {{ $loop->first ? '6px' : '0' }} 12px {{ $loop->first ? '0' : '6px' }}">
      <a href="{{ $linkFor(\Illuminate\Support\Str::slug($sv[1])) }}" style="display:block;text-decoration:none;background:#f6f8fb;border:1px solid #e6ebf2;border-radius:12px;padding:14px 16px">
        <span style="font-size:20px;line-height:1">{{ $sv[0] }}</span>
        <span style="display:block;margin-top:8px;font-size:15px;font-weight:bold;color:#0f172a">{{ $sv[1] }}</span>
        <span style="display:block;margin-top:3px;font-size:13px;line-height:1.45;color:#64748b">{{ $sv[2] }}</span>
      </a>
    </td>
    @endforeach
    @if (count($pair) === 1)<td class="col" width="50%"></td>@endif
  </tr>
  @endforeach
</table>
