<?php

namespace App\Listeners;

use App\Http\Controllers\OrderFlowController;
use Fleetbase\FleetOps\Events\OrderReady;
//use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class SendOrderReadyEmail
{
    public function __construct()
    {
        //
    }

    /**
     * Quote email, sent when an order is created.
     *
     * Travo update (Oct 2026): uses the shared customer email design (emails/travo/order-step.blade.php)
     * with payment details, and also goes to the order's customer. Internal copies
     * (hello@travoservices.com, sales@wigmoretrading.com) are bcc so customers don't see them.
     * Falls back to the previous template (emails/order_ready.blade.php) if the new one fails.
     */
    public function handle(OrderReady $event): void
    {
        try {
            $loggedInUser = Auth::user();
            $clientName  = $loggedInUser?->name ?? 'N/A';
            $clientPhone = $loggedInUser?->phone ?? $loggedInUser?->mobile ?? 'N/A';

            // Get the actual order model instance
            $order = $event->getModelRecord();
            if (!$order) {
                Log::error('❌ SendOrderReadyEmail: Could not fetch order record.');
                return;
            }

            $meta = $order->meta;
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }

            // Customer-facing recipients: the person the quote was shared with, the person who created it,
            // and the customer on the order.
            $recipients = [];
            $shareEmail = data_get($meta, 'shareQuote.email');
            if ($shareEmail) {
                $recipients[] = $shareEmail;
            }
            $name = 'Someone';
            if ($loggedInUser && $loggedInUser->email) {
                $recipients[] = $loggedInUser->email;
                $name = $loggedInUser->name;
            }
            $customer = rescue(fn () => OrderFlowController::recipient($order), null, false);
            if ($customer && $customer['source'] === 'customer') {
                $recipients[] = $customer['email'];
            }
            $recipients = array_values(array_unique(array_filter(array_map('strtolower', $recipients))));
            $internal   = OrderFlowController::COPIES;
            if (!$recipients) {
                $recipients = $internal;
                $internal   = [];
            }

            $orderType = data_get($order->toArray(), 'type', '');
            $isDriver = str_contains(strtoupper($orderType), 'DRIVER');
            $emoji = $isDriver ? "🚗" : "📦";
            $greet = data_get($meta, 'shareQuote.name') ?: ($customer['name'] ?? null) ?: $name;
            $first = trim(explode(' ', trim((string) $greet))[0]) ?: 'there';

            try {
                // client details: the customer on the order, else the person the quote was shared with, else whoever created it
                $extra = [];
                if (!$customer || $customer['source'] !== 'customer') {
                    $extra['client'] = $shareEmail
                        ? ['name' => data_get($meta, 'shareQuote.name'), 'phone' => data_get($meta, 'shareQuote.phone')]
                        : ['name' => $clientName, 'phone' => $clientPhone];
                }
                $view    = OrderFlowController::emailData($order, 'quote', $greet, $extra);
                $subject = "{$emoji} {$first}, almost booked… your Travo quote is ready" . ($view['q']['price'] ? ' (' . $view['q']['price'] . ')' : '');
                Mail::send('emails.travo.order-step', $view, function ($message) use ($recipients, $internal, $subject) {
                    $message->to($recipients)->subject($subject);
                    if ($internal) {
                        $message->bcc($internal);
                    }
                });
            } catch (\Throwable $e) {
                Log::error('SendOrderReadyEmail: new template failed, using the old one: ' . $e->getMessage());
                $subject = "{$emoji} {$name}, almost booked… your transport quote from Travo is ready";
                Mail::send('emails.order_ready', ['order_data' => $order->toArray(), 'client_name' => $clientName, 'client_phone' => $clientPhone], function ($message) use ($recipients, $internal, $subject) {
                    $message->to($recipients)->subject($subject);
                    if ($internal) {
                        $message->bcc($internal);
                    }
                });
            }

            Log::info('✅ Order ready email sent to: ' . implode(', ', $recipients));
        } catch (\Exception $e) {
            Log::error('🚨 Error in SendOrderReadyEmail: ' . $e->getMessage());
        }
    }
}
