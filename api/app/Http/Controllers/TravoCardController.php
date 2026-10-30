<?php

namespace App\Http\Controllers;

use App\Support\TravoCard;
use App\Support\TravoWallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Quickteller card payments: public pay pages (travo.ng/pay/...) and the console wallet top-up. */
class TravoCardController extends Controller
{
    private function view(array $data, int $status = 200)
    {
        return response()->view('travo-pay', $data + ['whatsapp' => OrderFlowController::WHATSAPP], $status)
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store');
    }

    /** GET: the order's pay page. POST: start a card payment and go to Quickteller. */
    public function order(Request $request, string $code)
    {
        $order = TravoCard::orderFromCode($code);
        if (!$order) {
            return $this->view(['mode' => 'error', 'title' => 'This payment link is not valid', 'text' => 'It may be incomplete. Open the link from your quote email again, or message us on WhatsApp.'], 404);
        }
        if ($request->isMethod('post')) {
            $r = TravoCard::startOrder($order);
            if (isset($r['error'])) {
                return $this->view(['mode' => 'error', 'title' => 'Card payment not started', 'text' => $r['error'], 'back' => TravoCard::orderUrl($order->public_id)]);
            }

            return $this->go($r['txn']);
        }
        $st = TravoCard::orderState($order);

        return $this->view([
            'mode' => 'order', 'st' => $st, 'ready' => TravoCard::ready(), 'bank' => OrderFlowController::BANK,
            'money' => fn ($k) => TravoWallet::money((int) $k),
            'trackUrl' => OrderTrackController::url($order->public_id),
        ]);
    }

    /** The auto-submitting hand-off to Quickteller (signed link, used by the wallet top-up). */
    public function goSigned(string $txn, string $sig)
    {
        if (!TravoCard::checkSig($txn, $sig)) {
            return $this->view(['mode' => 'error', 'title' => 'This payment link is not valid', 'text' => 'Please start the payment again.'], 404);
        }

        return $this->go($txn);
    }

    private function go(string $txn)
    {
        $p = DB::table(TravoCard::TABLE)->where('txn_ref', $txn)->first();
        if (!$p || $p->status !== 'pending') {
            return $this->view(['mode' => 'error', 'title' => 'This payment has already been handled', 'text' => 'Start a new payment if you still need to pay.']);
        }

        return $this->view(['mode' => 'redirect', 'form' => TravoCard::checkoutForm($p), 'amount' => TravoWallet::money((int) $p->amount)]);
    }

    /** Quickteller sends the customer back here (POST, sometimes GET). We re-check with Interswitch. */
    public function back(Request $request)
    {
        $txn = preg_replace('/[^A-Za-z0-9]/', '', (string) ($request->input('txnref') ?: $request->input('txn_ref') ?: $request->query('txnref')));
        if (!$txn) {
            return $this->view(['mode' => 'error', 'title' => 'We could not find this payment', 'text' => 'If money left your account, message us on WhatsApp with the time and amount and we will sort it out.']);
        }
        $p = TravoCard::confirm($txn);
        if (($p->status ?? '') === 'missing') {
            return $this->view(['mode' => 'error', 'title' => 'We could not find this payment', 'text' => 'If money left your account, message us on WhatsApp with the reference ' . $txn . '.']);
        }
        $order = $p->order_public_id ? \Fleetbase\FleetOps\Models\Order::where('public_id', $p->order_public_id)->first() : null;

        return $this->view([
            'mode' => 'result', 'p' => $p, 'amount' => TravoWallet::money((int) $p->amount),
            'trackUrl' => $order ? OrderTrackController::url($order->public_id) : null,
            'retryUrl' => $order ? TravoCard::orderUrl($order->public_id) : 'https://travo.ng/fleet-ops#wallet',
        ]);
    }

    /** Console: start a card top-up. POST int/v1/travo/wallet/card-topup {amount (naira)} */
    public function topup(Request $request)
    {
        $v    = str_replace([',', '₦', ' '], '', (string) $request->input('amount'));
        $kobo = is_numeric($v) ? (int) round(((float) $v) * 100) : 0;
        $r    = TravoCard::startTopup($request->user(), $kobo);

        return response()->json($r, isset($r['error']) ? 422 : 200);
    }

    /** Console: the card pay link for one of the user's orders. GET int/v1/travo/card/order/{id} */
    public function orderLink(Request $request, string $id)
    {
        $order = \Fleetbase\FleetOps\Models\Order::where('public_id', $id)->whereNull('deleted_at')->first();
        $u = $request->user();
        if (!$order || !(TravoWallet::canPay($u, $order) || (method_exists($u, 'isAdmin') && $u->isAdmin()))) {
            return response()->json(['error' => 'Order not found.'], 404);
        }

        return response()->json(['url' => TravoCard::orderUrl($order->public_id), 'ready' => TravoCard::ready()]);
    }

    /** Console: is card payment switched on? */
    public function status()
    {
        return response()->json(['ready' => TravoCard::ready(), 'min_topup' => TravoCard::settings()['min_topup']]);
    }
}
