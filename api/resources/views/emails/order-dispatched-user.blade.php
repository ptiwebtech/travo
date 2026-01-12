<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Order Dispatched</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f8f8f8; padding: 20px;">

    <!-- LOGO -->
    <img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png"
         alt="Travo"
         width="160"
         height="56"
         style="width: 100px !important; height: auto !important;
                margin: 0 auto 20px; display: block;">

    <!-- MAIN CARD -->
    <table align="center" width="600" cellpadding="0" cellspacing="0"
           style="background-color: #ffffff; border-radius: 8px;
                  padding: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">

        <tr>
            <td style="padding: 30px 20px">

                <!-- Greeting -->
                <p style="font-size: 15px; color: #333;">
                    Hello <strong>{{ $user->name }}</strong>,<br>
                    Your order <strong>{{ $order->public_id }}</strong> has now been dispatched 🚚
                </p>

                <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">

                <!-- ORDER DETAILS -->
                <h3 style="font-size: 17px; font-weight: 600; color: #222;">📦 Order Details</h3>
                <ul style="font-size: 14px; color: #444; line-height: 1.6; list-style-type: none; padding-left: 0;">
                    <li><strong>Tracking Number:</strong> {{ $order->tracking_number ?? 'N/A' }}</li>
                    <li><strong>Order ID:</strong> {{ $order->public_id }}</li>
                    <li><strong>Status:</strong> Dispatched</li>
                </ul>

                <!-- DRIVER DETAILS -->
                <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">

                <h3 style="font-size: 17px; font-weight: 600; color: #222;">🚗 Driver Assigned</h3>
                <ul style="font-size: 14px; color: #444; line-height: 1.6; list-style-type: none; padding-left: 0;">
                    <li><strong>Driver:</strong> {{ $driver ? $driver->name : 'Not Assigned' }}</li>
                </ul>

                <!-- VIEW ORDER BUTTON -->
                @php
                    $orderUrl = isset($order->public_id)
                                ? 'https://travo.ng/fleet-ops/' . $order->public_id
                                : url('/');
                @endphp

                <div style="text-align: center; margin-top: 30px;">
                    <a href="{{ $orderUrl }}" target="_blank"
                       style="background-color: #007bff; color: #ffffff; text-decoration: none;
                              padding: 12px 30px; border-radius: 6px; font-weight: 600;
                              display: inline-block;">
                        🔗 View Order
                    </a>
                </div>

                <br><br>

                <p style="font-size: 14px; color: #555; text-align:center;">
                    Thank you for using Travo!  
                </p>

            </td>
        </tr>

    </table>
</body>
</html>
