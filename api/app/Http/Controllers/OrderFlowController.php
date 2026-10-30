<?php

namespace App\Http\Controllers;

use Fleetbase\FleetOps\Events\OrderCompleted;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderDispatched as OrderDispatchedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Travo order progress: Quoted -> Payment received -> Dispatched / collected -> Delivered.
 *
 * Staff (platform admins only) move an order one step at a time from the Fleet-Ops order page.
 * Each step updates the order status, adds an activity entry, and emails the customer
 * (the order's customer, else the person the quote was shared with), with internal copies (bcc).
 *
 * Deliberately does NOT use Fleet-Ops' own dispatch/complete flow, because that refuses orders
 * without a driver and sends a separate (old-style) dispatch email.
 */
class OrderFlowController extends Controller
{
    /** internal copies of every customer email (bcc, so customers don't see them) */
    public const COPIES = ['hello@travoservices.com', 'sales@wigmoretrading.com'];

    public const BANK = ['name' => 'Travo Services Limited', 'bank' => 'OPay', 'number' => '6420527380'];

    public const WHATSAPP = 'https://wa.me/2349163352137';

    /** step key => [label, status code, tracking code, activity text, button text] */
    public const STEPS = [
        'quoted'     => ['label' => 'Quoted',      'short' => 'Quote sent'],
        'paid'       => ['label' => 'Payment received', 'short' => 'Paid', 'status' => 'payment_received', 'code' => 'PAYMENT_RECEIVED', 'activity' => 'Payment received', 'details' => 'Payment confirmed. The booking is confirmed.', 'button' => 'Payment received'],
        'dispatched' => ['label' => 'Dispatched / collected', 'short' => 'Dispatched', 'status' => 'dispatched', 'code' => 'DISPATCHED', 'activity' => 'Dispatched / collected', 'details' => 'The order has been dispatched or collected and is on its way.', 'button' => 'Dispatched / collected'],
        'delivered'  => ['label' => 'Delivered',   'short' => 'Delivered', 'status' => 'completed', 'code' => 'COMPLETED', 'activity' => 'Delivered', 'details' => 'The order has been delivered.', 'button' => 'Delivered'],
    ];

    /** GET int/v1/travo/orders/{id}/flow */
    public function show(Request $request, string $id)
    {
        $order = $this->find($id);
        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }
        $staff = $this->isStaff($request);

        return response()->json(self::state($order, $staff));
    }

    /** POST int/v1/travo/orders/{id}/flow  {step: paid|dispatched|delivered, notify: bool} */
    public function advance(Request $request, string $id)
    {
        if (!$this->isStaff($request)) {
            return response()->json(['error' => 'Only Travo admins can update the order progress.'], 403);
        }
        $order = $this->find($id);
        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }
        $data = $request->validate([
            'step'   => 'required|in:paid,dispatched,delivered',
            'notify' => 'boolean',
        ]);
        $step  = $data['step'];
        $state = self::state($order, true);
        if ($state['canceled']) {
            return response()->json(['error' => 'This order is canceled.'], 422);
        }
        if (($state['next']['key'] ?? null) !== $step) {
            return response()->json(['error' => 'This order is not ready for "' . self::STEPS[$step]['label'] . '". Refresh the page.'], 422);
        }

        self::applyStep($order, $step, $request->user());

        $mail = ['sent' => false, 'to' => null, 'warning' => null];
        if ($data['notify'] ?? true) {
            $mail = self::sendStepEmail($order->fresh(), $step);
        }
        if ($mail['sent']) {
            $flow                   = (array) data_get($order->fresh()->meta, 'travo_flow', []);
            $flow[$step]['emailed'] = $mail['to'];
            $order->fresh()->updateMeta('travo_flow', $flow);
        }

        return response()->json(['status' => 'ok', 'mail' => $mail] + self::state($order->fresh(), true));
    }

    /**
     * Moves an order to a step (status, activity entry, flow meta, side effects). No email, no checks:
     * callers check the order is ready for the step. Also used by wallet payments (TravoWallet).
     */
    public static function applyStep(Order $order, string $step, $user = null, ?string $details = null, array $extraFlow = []): void
    {
        $def = self::STEPS[$step];
        if ($details) {
            $def['details'] = $details;
        }

        DB::transaction(function () use ($order, $step, $def, $user, $extraFlow) {
            $order->status = $def['status'];
            if ($step === 'paid') {
                $order->paid_status = 'paid';
            }
            if ($step === 'dispatched') {
                $order->dispatched = true;
                $order->dispatched_at = $order->dispatched_at ?: now();
            }
            $order->save();

            $activity = new Activity(['code' => $def['code'], 'status' => $def['activity'], 'details' => $def['details']]);
            $location = rescue(fn () => $order->getLastLocation(), [], false) ?: [];
            $order->insertActivity($activity, $location);
            if ($order->payload && !$order->payload->isMultipleDropOrder) {
                foreach ($order->payload->entities as $entity) {
                    rescue(fn () => $entity->insertActivity($activity, $location), null, false);
                }
            }

            $flow        = (array) data_get($order->meta, 'travo_flow', []);
            $flow[$step] = ['at' => now()->toIso8601String(), 'by' => optional($user)->name] + $extraFlow;
            $order->updateMeta('travo_flow', $flow);
        });

        // side effects outside the transaction
        if ($step === 'dispatched' && $order->driver_assigned_uuid) {
            rescue(function () use ($order) {
                $driver = $order->driverAssigned;
                if ($driver) {
                    $driver->notify(new OrderDispatchedNotification($order));
                }
            }, null, false);
        }
        if ($step === 'delivered') {
            rescue(function () use ($order) {
                if ($order->driverAssigned && method_exists($order->driverAssigned, 'unassignCurrentOrder')) {
                    $order->driverAssigned->unassignCurrentOrder();
                }
            }, null, false);
            rescue(fn () => event(new OrderCompleted($order)), null, false);
        }
    }

    /**
     * Where the order is in the Travo flow. Works for old orders too (falls back to status / paid_status).
     */
    public static function state(Order $order, bool $withRecipient = false): array
    {
        $codes = DB::table('tracking_statuses')
            ->where('tracking_number_uuid', $order->tracking_number_uuid)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->get(['code', 'created_at']);
        $at = [];
        foreach ($codes as $c) {
            $at[strtoupper((string) $c->code)] = $at[strtoupper((string) $c->code)] ?? (string) $c->created_at;
        }
        $flow   = (array) data_get($order->meta, 'travo_flow', []);
        $status = strtolower((string) $order->status);
        $paidSt = strtolower((string) $order->paid_status);

        $delivered  = $status === 'completed' || isset($at['COMPLETED']) || isset($flow['delivered']);
        $dispatched = $delivered || in_array($status, ['dispatched', 'enroute', 'started', 'driver_enroute', 'in_transit'], true) || isset($at['DISPATCHED']) || isset($flow['dispatched']);
        $paid       = $dispatched || $paidSt === 'paid' || in_array($status, ['paid', 'payment_received'], true) || isset($at['PAYMENT_RECEIVED']) || isset($flow['paid']);
        $canceled   = $status === 'canceled' || $status === 'cancelled';

        $time = function ($step, $code) use ($flow, $at) {
            $t = data_get($flow, $step . '.at') ?: ($at[$code] ?? null);

            return $t ? \Carbon\Carbon::parse($t)->toIso8601String() : null;
        };
        $steps = [
            ['key' => 'quoted', 'label' => self::STEPS['quoted']['label'], 'done' => true, 'at' => optional($order->created_at)->toIso8601String()],
            ['key' => 'paid', 'label' => self::STEPS['paid']['label'], 'done' => $paid, 'at' => $paid ? $time('paid', 'PAYMENT_RECEIVED') : null, 'by' => data_get($flow, 'paid.by'), 'emailed' => data_get($flow, 'paid.emailed')],
            ['key' => 'dispatched', 'label' => self::STEPS['dispatched']['label'], 'done' => $dispatched, 'at' => $dispatched ? $time('dispatched', 'DISPATCHED') : null, 'by' => data_get($flow, 'dispatched.by'), 'emailed' => data_get($flow, 'dispatched.emailed')],
            ['key' => 'delivered', 'label' => self::STEPS['delivered']['label'], 'done' => $delivered, 'at' => $delivered ? $time('delivered', 'COMPLETED') : null, 'by' => data_get($flow, 'delivered.by'), 'emailed' => data_get($flow, 'delivered.emailed')],
        ];
        $current = 'quoted';
        foreach ($steps as $s) {
            if ($s['done']) {
                $current = $s['key'];
            }
        }
        $next = null;
        if (!$canceled) {
            foreach ($steps as $s) {
                if (!$s['done']) {
                    $next = ['key' => $s['key'], 'label' => $s['label'], 'button' => self::STEPS[$s['key']]['button']];
                    break;
                }
            }
        }

        $out = ['order' => $order->public_id, 'paymentRef' => self::paymentRef($order), 'trackUrl' => OrderTrackController::url($order->public_id), 'current' => $current, 'canceled' => $canceled, 'steps' => $steps, 'next' => $next];
        if ($withRecipient) {
            $out['recipient'] = self::recipient($order);
            $out['copies']    = self::COPIES;
        }

        return $out;
    }

    /** The customer's email, else the last person the quote was shared with. */
    public static function recipient(Order $order): ?array
    {
        $email = null;
        $name  = null;
        if ($order->customer_uuid) {
            foreach (['contacts', 'vendors', 'users'] as $table) {
                $row = DB::table($table)->where('uuid', $order->customer_uuid)->first(['name', 'email']);
                if ($row && filter_var($row->email, FILTER_VALIDATE_EMAIL)) {
                    $email = $row->email;
                    $name  = $row->name;
                    break;
                }
            }
        }
        if ($email) {
            return ['email' => $email, 'name' => $name, 'source' => 'customer'];
        }
        $shared = (string) data_get($order->meta, 'Shared With Details', '');
        $last   = null;
        foreach (preg_split('/\r?\n/', $shared) as $line) {
            $parts = array_map('trim', explode('|', preg_replace('/^\(\d+\)\s*/', '', $line)));
            if (count($parts) >= 2 && filter_var($parts[1], FILTER_VALIDATE_EMAIL)) {
                $last = ['email' => $parts[1], 'name' => $parts[0] !== 'Guest' ? $parts[0] : null, 'source' => 'quote recipient'];
            }
        }

        return $last;
    }

    /** Email the customer about a step. Returns ['sent' => bool, 'to' => ?string, 'warning' => ?string]. */
    public static function sendStepEmail(Order $order, string $step): array
    {
        $to = self::recipient($order);
        if (!$to) {
            return ['sent' => false, 'to' => null, 'warning' => 'No customer email on this order, so no email was sent.'];
        }
        try {
            $view    = self::emailData($order, $step, $to['name']);
            $subject = $view['subject'];
            Mail::send('emails.travo.order-step', $view, function ($m) use ($to, $subject) {
                $m->to($to['email'], $to['name'] ?: null)->bcc(self::COPIES)->subject($subject);
            });

            return ['sent' => true, 'to' => $to['email'], 'warning' => null];
        } catch (\Throwable $e) {
            Log::error('Travo step email failed: ' . $e->getMessage(), ['order' => $order->public_id, 'step' => $step]);

            return ['sent' => false, 'to' => $to['email'], 'warning' => 'The email could not be sent: ' . $e->getMessage()];
        }
    }

    /** Everything the email template needs, for any step (also used by the quote and share emails). */
    public static function emailData(Order $order, string $step, ?string $name = null, array $extra = []): array
    {
        $order->loadMissing(['payload.pickup', 'payload.dropoff', 'payload.entities', 'purchaseRate.serviceQuote.items']);
        $q       = app(QuoteShareController::class)->buildQuote($order, QuoteShareController::code($order->public_id));
        $state   = self::state($order);
        $first   = $name ? trim(explode(' ', trim($name))[0]) : null;
        $ref     = self::paymentRef($order);
        $driver  = rescue(fn () => optional($order->driverAssigned)->name, null, false);
        $isRide  = str_contains(strtoupper((string) $order->type), 'DRIVER');
        $emoji   = $isRide ? '🚗' : '📦';
        $price   = $q['price'] ? ' (' . $q['price'] . ')' : '';

        $copy = [
            'quote' => [
                'eyebrow' => 'Quote ready',
                'title'   => 'Your quote is ready',
                'intro'   => 'Here is your quote for ' . $q['orderType'] . '. To confirm your booking, please make payment using the details below. Your quote is held for 24 hours.',
                'cta'     => 'View your quote',
                'subject' => $emoji . ' Your Travo quote is ready' . $price . ' – pay to confirm',
            ],
            'paid' => [
                'eyebrow' => 'Payment received',
                'title'   => 'Payment received, thank you',
                'intro'   => 'We have received your payment' . ($q['price'] ? ' of ' . $q['price'] : '') . ' and your booking is confirmed. We are getting everything ready and will email you as soon as it is dispatched.',
                'cta'     => 'View your order',
                'subject' => '✅ Payment received – your Travo booking ' . $ref . ' is confirmed',
            ],
            'dispatched' => [
                'eyebrow' => 'On its way',
                'title'   => 'Your order is on its way',
                'intro'   => 'Good news: your order has been dispatched' . ($driver ? ' with ' . $driver : '') . ' and is on its way. We will let you know when it has been delivered.',
                'cta'     => 'View your order',
                'subject' => '🚚 Your Travo order ' . $ref . ' is on its way',
            ],
            'delivered' => [
                'eyebrow' => 'Delivered',
                'title'   => 'Delivered. Thanks for choosing Travo',
                'intro'   => 'Your order has been delivered. We hope everything went smoothly. If anything was not right, just reply to this email and we will sort it out.',
                'cta'     => 'Book your next trip',
                'subject' => '🎉 Your Travo order ' . $ref . ' has been delivered',
            ],
        ][$step];

        // tracking page (https://travo.ng/track/...): the main button for paid / dispatched, a link on the others
        $trackUrl = OrderTrackController::url($order->public_id);
        if (in_array($step, ['paid', 'dispatched'], true)) {
            $copy['cta'] = 'Track your order';
        }
        $ctaUrl = $step === 'delivered'
            ? 'https://travo.ng/?utm_source=travo&utm_medium=email&utm_campaign=delivered'
            : (in_array($step, ['paid', 'dispatched'], true) ? $trackUrl : $q['url']);

        // full addresses + Google Maps links, distance and client details (quote email; same as the original quote email)
        $addr = function ($place) {
            $a = trim((string) data_get($place, 'address'));
            if ($a === '') {
                $a = trim(implode(', ', array_filter([data_get($place, 'name'), data_get($place, 'street1'), data_get($place, 'city')])));
            }

            return $a !== '' ? ['text' => $a, 'map' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($a)] : null;
        };
        $client = ['name' => null, 'phone' => null];
        if ($order->customer_uuid) {
            foreach (['contacts', 'vendors', 'users'] as $table) {
                $row = DB::table($table)->where('uuid', $order->customer_uuid)->first();
                if ($row) {
                    $client = ['name' => $row->name ?? null, 'phone' => $row->phone ?? null];
                    break;
                }
            }
        }

        return array_merge([
            'pickupFull'  => $addr(optional($order->payload)->pickup),
            'dropoffFull' => $addr(optional($order->payload)->dropoff),
            'distance'    => $q['distanceKm'] ? $q['distanceKm'] . ' km' : null,
            'client'      => $client,
            'showDetails' => $step === 'quote',
            'step'     => $step,
            'q'        => $q,
            'steps'    => $state['steps'],
            'current'  => $step === 'quote' ? 'quoted' : $step,
            'first'    => $first,
            'ref'      => $ref,
            'tracking' => optional($order->trackingNumber)->tracking_number,
            'driver'   => $driver,
            'bank'     => self::BANK,
            'showPay'  => $step === 'quote',
            'ctaUrl'   => $ctaUrl,
            'orderUrl' => $q['url'],
            'trackUrl' => $trackUrl,
            'whatsapp' => self::WHATSAPP,
            'walletPaid' => $walletPaid = \App\Support\TravoWallet::walletPaid($order->uuid),
            'leftToPay'  => $walletPaid > 0 && $q['amount'] ? \App\Support\TravoWallet::money(max(0, $q['amount'] - $walletPaid)) : null,
            'walletPaidText' => $walletPaid > 0 ? \App\Support\TravoWallet::money($walletPaid) : null,
        ], $copy, $extra);
    }

    /**
     * Unique payment reference for bank transfers: TRV- + the order number (order_31JhjaR -> TRV-31JHJAR).
     * (The internal ID can't be used: most orders share the same default, e.g. SEND001001.)
     * Staff can find the order by searching the part after "TRV-".
     */
    public static function paymentRef(Order $order): string
    {
        return 'TRV-' . strtoupper(preg_replace('/^order_/', '', (string) $order->public_id));
    }

    private function find(string $id): ?Order
    {
        return Order::where('public_id', $id)->first() ?? Order::where('uuid', $id)->first();
    }

    /** Only Travo platform admins (users.type = admin), the same people who see the "Payment received" button. */
    private function isStaff(Request $request): bool
    {
        $user = $request->user();

        return $user && method_exists($user, 'isAdmin') && $user->isAdmin();
    }
}
