{{-- Travo: "My Network" promo panel at the bottom of every Travo email (27 Oct 2026).
     Include inside the email's main table: @include('emails.travo._network-promo', ['pad' => 28]) --}}
@php $pad = $pad ?? 28; @endphp
<tr><td style="padding:18px {{ $pad }}px 4px">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px">
    <tr><td style="padding:16px 18px">
      <p style="margin:0 0 6px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:#065f46">&#8358; Earn 1% of your contacts&rsquo; bookings</p>
      <p style="margin:0 0 10px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#334155">
        Click <b>My Network</b> on your Travo dashboard and add the people you work with. When they join and book, 1% of every booking they pay for goes into your Travo wallet for 12 months. Spend it on your own orders or cash it out.
      </p>
      <a href="https://travo.ng/fleet-ops#my-network" style="font-family:Arial,Helvetica,sans-serif;color:#047857;font-weight:bold;font-size:14px">Open My Network &rarr;</a>
    </td></tr>
  </table>
</td></tr>
