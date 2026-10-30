<?php

namespace App\Notifications;

use App\Http\Controllers\OrderFlowController;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Fleetbase\FleetOps\Models\Order;

/**
 * Sent when an order is dispatched through Fleet-Ops' own dispatch (not the Travo order progress buttons,
 * which send their own email).
 *
 * Travo update (Oct 2026): shared customer email design; goes to the customer with internal copies
 * (hello@travoservices.com, sales@wigmoretrading.com) as bcc. No longer copies the developer's address.
 */
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

        Log::info('📤 Sending Order Dispatch Email...', [
            'email'    => $notifiable->email,
            'order_id' => $this->order->public_id,
        ]);

        try {
            try {
                $view = OrderFlowController::emailData($this->order, 'dispatched', $notifiable->name ?? null);
                Mail::send('emails.travo.order-step', $view, function ($message) use ($notifiable, $view) {
                    $message->to($notifiable->email)->bcc(OrderFlowController::COPIES)->subject($view['subject']);
                });
            } catch (\Throwable $e) {
                Log::error('OrderDispatchedToUser: new template failed, using the old one: ' . $e->getMessage());
                Mail::send('emails.order-dispatched-user', [
                    'user'   => $notifiable,
                    'order'  => $this->order,
                    'driver' => $driver,
                ], function ($message) use ($notifiable) {
                    $message->to($notifiable->email)->bcc(OrderFlowController::COPIES)->subject('Your Order Has Been Dispatched!');
                });
            }
            Log::info('📨 Dispatch email sent', ['email' => $notifiable->email]);
        } catch (\Exception $e) {
            Log::error('🔥 Email sending failed!', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return null;
    }
}
