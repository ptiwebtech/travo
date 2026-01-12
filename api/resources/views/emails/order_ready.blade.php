<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Order Details</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f8f8f8; padding: 20px;">
        <img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo" width="160" height="56" style="width: 100px !important; height: auto !important;
                margin: 0 auto 20px; display: block;">
    <table align="center" width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; padding: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
    @php
        $meta = $order_data['meta'] ?? null;

        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        $shareName = data_get($meta, 'shareQuote.name');
        $greetingName = $shareName ?: $order_data['customer_name'];

        $extraLabels = [
            'package_required' => 'Package Required',
            'add_insurance' => 'Insurance Added',
            'road_settlement' => 'Road Settlement',
            'runway_reception' => 'Runway Reception',
            'immigration_fast_track' => 'Immigration Fast Track',
            'visa_facilitation_desk' => 'Visa Facilitation Desk',
            'baggage_assistance' => 'Baggage Assistance',
            'arrival_lounge_escort' => 'Arrival Lounge Escort',
            'family_child_assistance' => 'Family/Child Assistance',
            'executive_escort_service' => 'Executive Escort Service',
            'protocol_diplomatic_handling' => 'Protocol/Diplomatic Handling',
            'medical_accessibility_assistance' => 'Medical/Accessibility Assistance'
        ];

        // Filter meta to find which extras are present and set to true
        $enabledExtras = [];
        foreach ($extraLabels as $key => $label) {
            if (data_get($meta, $key) === true) {
                $enabledExtras[] = $label;
            }
        }
    @endphp
        <tr>
            <td style="padding: 30px 20px">
                <p style="font-size: 15px; color: #333;">
                    Hello <strong></strong>{{ $greetingName }},<br>
                    New quote created <strong>#{{ $order_data['public_id'] ?? 'N/A' }}</strong>
                </p>
                <p style="font-size: 15px; color: #333;">
                    Make payment immediately using the details below to initiate order. We Look forword to handling your order. 
                </p>
                <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">

                {{-- ORDER DETAILS --}}
                @php
                    $orderType = $order_data['type'] ?? '';
                    $isDriver = str_contains(strtoupper($orderType), 'DRIVER');
                    $orderIcon = $isDriver ? "🚗" : "📦";
                @endphp
                <h3 style="font-size: 17px; font-weight: 600; color: #222;">{{ $orderIcon }} Order Details</h3>
                <!-- <h3 style="font-size: 17px; font-weight: 600; color: #222;">📦 Order Details</h3> -->
                <ul style="font-size: 14px; color: #444; line-height: 1.6; list-style-type: none; padding-left: 0;">
                    <li><strong>Tracking Number:</strong> {{ $order_data['tracking'] ?? 'N/A' }}</li>
                    <li><strong>Internal ID:</strong> {{ $order_data['internal_id'] ?? 'N/A' }}</li>
                    <li><strong>Order Type:</strong> {{ data_get($meta, 'order_type_name') ?? 'N/A' }}</li>
                    <li><strong>Servive Name:</strong> {{ data_get($meta, 'service_name') ?? 'N/A' }}</li>
                    <li><strong>Status:</strong> {{ ucfirst($order_data['status'] ?? 'unknown') }}</li>
                    <li><strong>Scheduled At:</strong> {{ \Carbon\Carbon::parse($order_data['scheduled_at'])->format('d M Y, h:i A') }}</li>
                </ul>

                {{-- DRIVER DETAILS --}}
                @if(!empty($order_data['driver_name']) || !empty($order_data['vehicle_assigned_uuid']))
                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">
                    <h3 style="font-size: 17px; font-weight: 600; color: #222;">🚗 Driver Details</h3>
                    <ul style="font-size: 14px; color: #444; line-height: 1.6; list-style-type: none; padding-left: 0;">
                        <li><strong>Driver Name:</strong> {{ $order_data['driver_name'] ?? 'Not Assigned' }}</li>
                    </ul>
                @endif

                {{-- CUSTOMER DETAILS --}}
                @if(!empty($order_data['customer_name']) || !empty($order_data['customer_phone']))
                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">
                    <h3 style="font-size: 17px; font-weight: 600; color: #222;">👤 Customer Details</h3>
                    <ul style="font-size: 14px; color: #444; line-height: 1.6; list-style-type: none; padding-left: 0;">
                        <li><strong>Name:</strong> {{ $order_data['customer_name'] ?? 'N/A' }}</li>
                        <li><strong>Phone:</strong> {{ $order_data['customer_phone'] ?? 'N/A' }}</li>
                    </ul>
                @endif

                {{-- Additional Services (Extras) --}}
                @if(count($enabledExtras) > 0)
                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">
                    <h3 style="font-size: 17px; font-weight: 600; color: #222;">✨ Additional Services (Extras)</h3>
                    <ul style="font-size: 14px; color: #444; line-height: 1.6; list-style-type: none; padding-left: 0;">
                        @foreach($enabledExtras as $extra)
                            <li><span style="color: #28a745;">✔</span> {{ $extra }}</li>
                        @endforeach
                    </ul>
                @endif

                {{-- DISTANCE & SERVICE QUOTATION --}}
                @if(!empty($order_data['distance']) || !empty($order_data['purchase_rate_id']))
                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">
                    <h3 style="font-size: 17px; font-weight: 600; color: #222;">📏 Distance</h3>
                    <ul style="font-size: 14px; color: #444; line-height: 1.6; list-style-type: none; padding-left: 0;">
                        <li><strong>Distance:</strong> 
                            @if(!empty($order_data['distance']))
                                {{ number_format(($order_data['distance'] / 1000), 2) }} km
                            @else
                                N/A
                            @endif
                        </li>
                    </ul>
                @endif


                {{-- LOCATION DETAILS --}}
                @if(isset($order_data['payload']['pickup']) || isset($order_data['payload']['dropoff']))
                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">
                    <h3 style="font-size: 17px; font-weight: 600; color: #222;">📍 Route Information</h3>
                    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                        <tr>
                            <td style="padding: 0 6px 0 0; vertical-align: sub;"><strong>Pickup:</strong></td>
                            <td>
                                @if(!empty($order_data['payload']['pickup']['address']))
                                    <a href="https://www.google.com/maps/search/{{ urlencode($order_data['payload']['pickup']['address']) }}" target="_blank" class="hover:underline;" style="color: #000;">
                                        {{ $order_data['payload']['pickup']['address'] }}
                                    </a>
                                @else
                                    N/A
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 0 6px 0 0; vertical-align: sub;"><strong>Dropoff:</strong></td>
                            <td>
                                @if(!empty($order_data['payload']['dropoff']['address']))
                                    <a href="https://www.google.com/maps/search/{{ urlencode($order_data['payload']['dropoff']['address']) }}" target="_blank" class="hover:underline;" style="color: #000;">
                                        {{ $order_data['payload']['dropoff']['address'] }}
                                    </a>
                                @else
                                    N/A
                                @endif
                            </td>
                        </tr>
                    </table>
                @endif


                {{-- TRANSACTION DETAILS --}}
                @if(isset($order_data['transaction']))
                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">
                    <h3 style="font-size: 17px; font-weight: 600; color: #222;">💳 Transaction Details</h3>
                    <ul style="font-size: 14px; color: #444; line-height: 1.6; list-style-type: none; padding-left: 0;">
                        <li><strong>Transaction ID:</strong> {{ $order_data['transaction']['gateway_transaction_id'] ?? 'N/A' }}</li>
                        <li><strong>Amount:</strong> <b style="font-size: 16px;">{{ number_format(($order_data['transaction']['amount'] ?? 0) / 100, 2) }} NGN</b></li>
                        <li><strong>Status:</strong> {{ ucfirst('payment Pending') }}</li>
                        <li><strong>Quote Expiry: </strong>24 Hours</li>
                    </ul>
                @endif

                {{-- Payments DETAILS --}}
                <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">
                <h3 style="font-size: 17px; font-weight: 600; color: #222;">💳 Payment Details</h3>
                <p style="font-size: 14px; font-weight: 400; color: #222;">Make Payment to account below:</p>
                <ul style="font-size: 14px; color: #444; line-height: 1.6; list-style-type: none; padding-left: 0;">
                    <li><strong>Account Name:</strong> Travo Services Limited</li>
                    <li><strong>BANK:</strong> OPay</li>
                    <li><strong>Account Number:</strong> 6420527380</li>
                </ul>


                {{-- VIEW ORDER BUTTON --}}
                @php
                    $orderPublicId = $order_data['public_id'] ?? null;
                    $orderUrl = $orderPublicId ? 'https://travo.ng/fleet-ops/' . $orderPublicId : '#';
                @endphp

                <div style="text-align: center; margin-top: 30px;">
                    <a href="{{ $orderUrl }}" target="_blank" style="background-color: #007bff; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 6px; font-weight: 600; display: inline-block;">
                        🔗 View Order
                    </a>
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
