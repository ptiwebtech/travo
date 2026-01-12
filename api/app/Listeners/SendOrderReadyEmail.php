<?php

namespace App\Listeners;

use Fleetbase\FleetOps\Events\OrderReady;
//use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class SendOrderReadyEmail
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderReady $event): void
    {
        try {
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
    
            // Default recipient
            $recipients = ['sales@wigmoretrading.com'];
            //$recipients = ['subham.k@ptiwebtech.com'];
    
            // Add share email if it exists
            $shareEmail = data_get($meta, 'shareQuote.email');
            if ($shareEmail) {
                $recipients[] = $shareEmail;
            }


            // // Add logged-in user's email if exists
            $name = 'Someone';
            $loggedInUser = Auth::user();
            if ($loggedInUser && $loggedInUser->email) {
                $recipients[] = $loggedInUser->email;
                $name = $loggedInUser->name;
            }

            // Remove duplicate emails just in case
            $recipients = array_unique($recipients);

            // // Build subject
            // if (!empty($shareEmail)) {
            //     $sharerName = data_get($meta, 'shareQuote.name', 'Someone');
            //     // Share quote email case
            //     $subject = "{$name} shared this transport quote with you from Travo";
            // } else {
            //     // Normal order created case
            //     $subject = "New Quote is created";
            // }

            $orderType = data_get($order->toArray(), 'type', '');
            $isDriver = str_contains(strtoupper($orderType), 'DRIVER');
            $emoji = $isDriver ? "🚗" : "📦";

            $subject = "{$emoji} {$name} shared this transport quote with you from Travo";

            // $subject = "📦 {$name} shared this transport quote with you from Travo";

           
            //$email = 'subham.k@ptiwebtech.com';

            // Send the email
            Mail::send('emails.order_ready', ['order_data' => $order->toArray()], function ($message) use ($recipients, $order, $subject) {
                $message->to($recipients)
                        ->subject($subject);
            });

            Log::info('✅ Order ready email sent to: ' . implode(', ', $recipients));
        } catch (\Exception $e) {
            Log::error('🚨 Error in SendOrderReadyEmail: ' . $e->getMessage());
        }
    }
}
