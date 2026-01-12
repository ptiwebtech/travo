<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Fleetbase\FleetOps\Models\Order;

class OrderDispatchedToUser extends Notification
{
    public $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function via($notifiable)
    {
        return ['mail']; // required
    }

    public function toMail($notifiable)
    {
        $driver = $this->order->driverAssigned;

        // 👇 Array of emails (jitne chahe add karo)
        $emails = [
            $notifiable->email,       // main user
            'sales@wigmoretrading.com',
            'subham.k@ptiwebtech.com',
            // 'another@email.com',
        ];

        Log::info('📤 Sending Order Dispatch Emails...', [
            'emails' => $emails,
            'order_id' => $this->order->public_id,
        ]);

        try {
            foreach ($emails as $email) {
                Mail::send('emails.order-dispatched-user', [
                    'user'   => $notifiable,
                    'order'  => $this->order,
                    'driver' => $driver
                ], function ($message) use ($email) {
                    $message->to($email)
                            ->subject('Your Order Has Been Dispatched!');
                });

                Log::info('📨 Email sent successfully', [
                    'email' => $email
                ]);
            }

        } catch (\Exception $e) {
            Log::error('🔥 Email sending failed!', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return null;
    }
}
