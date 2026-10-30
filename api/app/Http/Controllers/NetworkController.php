<?php

namespace App\Http\Controllers;

use App\Support\TravoNetwork;
use App\Support\TravoWallet;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Travo "My Network" and "Wallet" (27 Oct 2026).
 *
 * Members (any signed-in user):
 *   GET  int/v1/travo/network                      link, stats, invites, members, earnings
 *   POST int/v1/travo/network/invites              {contacts:[{email,name}], source}
 *   POST int/v1/travo/network/invites/{id}/remind
 *   POST int/v1/travo/network/claim                {code, invite}   (called once after sign-up)
 *   GET  int/v1/travo/wallet                       balance, history, bank details, orders it can pay
 *   POST int/v1/travo/wallet/topup                 {amount}  naira
 *   POST int/v1/travo/wallet/pay                   {order}   public_id
 *   POST int/v1/travo/wallet/payout                {amount, bank, account_number, account_name}
 * Staff (platform admins):
 *   GET  int/v1/travo/admin/wallet                 pending top-ups / payouts, recent entries, referrals
 *   POST int/v1/travo/admin/wallet/{ref}           {decision: approve|reject, amount?, note?}
 *   POST int/v1/travo/admin/wallet-adjust          {user, amount, reason}
 * Public:
 *   GET  /share/r/{code}                           referral link -> sign-up
 *   GET|POST /share/r/{code}/no/{token}            "don't invite me again"
 */
class NetworkController extends Controller
{
    // ---- public -------------------------------------------------------------------------------

    public function landing(Request $request, string $code)
    {
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $request->query('i')) ?: null;
        $to    = TravoNetwork::landing($code, $token);
        $res   = redirect()->away($to ?: TravoNetwork::settings()['onboard_url'], 302)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
        if ($to) {
            // read by the console add-on after sign-up (the sign-up page can drop the ?ref= from the address)
            $val = strtolower(preg_replace('/[^A-Za-z0-9-]/', '', $code)) . ($token ? '.' . $token : '');
            $res->headers->set('Set-Cookie', 'travo_ref=' . $val . '; Max-Age=2592000; Path=/; Secure; SameSite=Lax', false);
        }

        return $res;
    }

    public function optOut(Request $request, string $code, string $token)
    {
        $inv = TravoNetwork::inviteFromOptOut($code, $token);
        $done = false;
        if ($inv && $request->isMethod('post')) {
            TravoNetwork::suppress($inv->email, 'opted_out');
            $done = true;
        }

        return response()->view('travo-network-optout', [
            'valid' => (bool) $inv, 'done' => $done || ($inv && TravoNetwork::suppressed($inv->email)),
            'email' => $inv ? $inv->email : null,
        ])->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store');
    }

    // ---- network ------------------------------------------------------------------------------

    public function network(Request $request)
    {
        return response()->json(TravoNetwork::overview($request->user()));
    }

    public function invite(Request $request)
    {
        $data = $request->validate([
            'contacts'         => 'required|array|min:1|max:500',
            'contacts.*.email' => 'required|string|max:191',
            'contacts.*.name'  => 'nullable|string|max:200',
            'source'           => 'nullable|string|max:20',
        ]);
        $res = TravoNetwork::addInvites($request->user(), $data['contacts'], $data['source'] ?? 'paste');
        if (!empty($res['error'])) {
            return response()->json($res, 422);
        }

        return response()->json($res + ['network' => TravoNetwork::overview($request->user())]);
    }

    public function remind(Request $request, int $id)
    {
        $res = TravoNetwork::requestReminder($request->user(), $id);

        return response()->json($res + ($res['ok'] ? ['network' => TravoNetwork::overview($request->user())] : []), $res['ok'] ? 200 : 422);
    }

    public function claim(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:40', 'invite' => 'nullable|string|max:40']);
        $res  = TravoNetwork::claim($request->user(), $data['code'], $data['invite'] ?? null);

        // the console only needs to know it can forget the code
        return response()->json(['ok' => $res['ok'], 'referrer' => $res['referrer'] ?? null]);
    }

    // ---- wallet -------------------------------------------------------------------------------

    public function wallet(Request $request)
    {
        $user = $request->user();
        $s    = TravoNetwork::settings();

        return response()->json([
            'balances'   => TravoWallet::balances($user->uuid),
            'entries'    => TravoWallet::entries($user->uuid),
            'bank'       => OrderFlowController::BANK,
            'min_payout' => (int) $s['min_payout'],
            'min_topup'  => (int) $s['min_topup'],
            'payable'    => $this->payableOrders($user),
            'pending_topups' => DB::table(TravoWallet::ENTRIES)->where('user_uuid', $user->uuid)->where('type', 'topup')->where('status', 'pending')->count(),
        ]);
    }

    /** Recent unpaid orders the user can pay from the wallet, with the amount due. */
    private function payableOrders(object $user): array
    {
        $orders = Order::whereNull('deleted_at')
            ->where(function ($q) use ($user) {
                $q->where('created_by_uuid', $user->uuid)->orWhere('customer_uuid', $user->uuid);
                if ($user->company_uuid && !$user->isAdmin()) {
                    $q->orWhere('company_uuid', $user->company_uuid);
                }
            })
            ->whereNotIn('status', ['canceled', 'cancelled', 'completed', 'payment_received', 'dispatched'])
            ->where(function ($q) {
                $q->whereNull('paid_status')->orWhereRaw('LOWER(paid_status) <> ?', ['paid']);
            })
            ->where('created_at', '>=', now()->subDays(90))
            ->orderByDesc('created_at')->limit(12)->get();
        $out = [];
        foreach ($orders as $o) {
            $state = OrderFlowController::state($o);
            if ($state['canceled'] || ($state['next']['key'] ?? null) !== 'paid') {
                continue;
            }
            $due = rescue(fn () => TravoWallet::amountDue($o), null, false);
            if (!$due) {
                continue;
            }
            $paid  = TravoWallet::walletPaid($o->uuid);
            $out[] = [
                'order' => $o->public_id, 'ref' => OrderFlowController::paymentRef($o), 'amount' => $due, 'amount_text' => TravoWallet::money($due),
                'paid' => $paid, 'left' => max(0, $due - $paid),
                'created_at' => optional($o->created_at)->toIso8601String(),
                'label' => Str::limit(trim((string) (data_get($o->meta, 'title') ?: optional($o->payload)->dropoff_name ?: $o->type ?: 'Order')), 60),
            ];
        }

        return $out;
    }

    private function naira($v): ?int
    {
        $v = str_replace([',', '₦', ' '], '', (string) $v);

        return is_numeric($v) ? (int) round(((float) $v) * 100) : null;
    }

    public function topup(Request $request)
    {
        $user = $request->user();
        $kobo = $this->naira($request->input('amount'));
        $min  = (int) TravoNetwork::settings()['min_topup'];
        if (!$kobo || $kobo < $min || $kobo > 1000000000) {
            return response()->json(['error' => 'Enter an amount of at least ' . TravoWallet::money($min) . '.'], 422);
        }
        if (DB::table(TravoWallet::ENTRIES)->where('user_uuid', $user->uuid)->where('type', 'topup')->where('status', 'pending')->count() >= 5) {
            return response()->json(['error' => 'You have 5 top-ups waiting to be confirmed. We will confirm them as soon as the money arrives.'], 422);
        }
        $e = TravoWallet::requestTopup($user, $kobo);
        TravoNetwork::notifyStaff('Top-up request ' . $e->public_id . ' ' . TravoWallet::money($kobo), [
            'Customer: ' . $user->name . ' <' . $user->email . '>',
            'Amount: ' . TravoWallet::money($kobo),
            'Transfer reference to look for: ' . $e->public_id,
            'When the money is in the OPay account, approve it in Wallet admin.',
        ]);

        return response()->json(['ok' => true, 'ref' => $e->public_id, 'amount' => $kobo, 'bank' => OrderFlowController::BANK]);
    }

    public function pay(Request $request)
    {
        $data  = $request->validate(['order' => 'required|string|max:40', 'amount' => 'nullable']);
        $user  = $request->user();
        $order = Order::where('public_id', $data['order'])->orWhere('uuid', $data['order'])->first();
        if (!$order || !TravoWallet::canPay($user, $order)) {
            return response()->json(['error' => 'Order not found.'], 404);
        }
        $kobo = isset($data['amount']) && $data['amount'] !== '' ? $this->naira($data['amount']) : null;
        if (isset($data['amount']) && $data['amount'] !== '' && !$kobo) {
            return response()->json(['error' => 'Enter the amount you want to pay.'], 422);
        }
        $res = TravoWallet::payOrder($user, $order, $kobo);

        return response()->json($res + ($res['ok'] ? ['balances' => TravoWallet::balances($user->uuid)] : []), $res['ok'] ? 200 : 422);
    }

    public function payout(Request $request)
    {
        $data = $request->validate([
            'amount'         => 'required',
            'bank'           => 'required|string|max:80',
            'account_number' => ['required', 'regex:/^\d{10}$/'],
            'account_name'   => 'required|string|max:120',
        ]);
        $user = $request->user();
        $kobo = $this->naira($data['amount']);
        if (!$kobo) {
            return response()->json(['error' => 'Enter the amount.'], 422);
        }
        $bank = ['bank' => strip_tags($data['bank']), 'number' => $data['account_number'], 'name' => strip_tags($data['account_name'])];
        $res  = TravoWallet::requestPayout($user, $kobo, $bank, (int) TravoNetwork::settings()['min_payout']);
        if ($res['ok']) {
            TravoNetwork::notifyStaff('Payout request ' . $res['ref'] . ' ' . TravoWallet::money($kobo), [
                'Member: ' . $user->name . ' <' . $user->email . '>',
                'Amount: ' . TravoWallet::money($kobo),
                'Pay to: ' . $bank['name'] . ', ' . $bank['bank'] . ' ' . $bank['number'],
                'Check the account name matches the member before paying. Then mark it paid in Wallet admin.',
            ]);
        }

        return response()->json($res + ['balances' => TravoWallet::balances($user->uuid)], $res['ok'] ? 200 : 422);
    }

    // ---- staff --------------------------------------------------------------------------------

    private function staff(Request $request): bool
    {
        $u = $request->user();

        return $u && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    public function admin(Request $request)
    {
        if (!$this->staff($request)) {
            return response()->json(['error' => 'Only Travo admins can see this.'], 403);
        }
        $pending = DB::table(TravoWallet::ENTRIES)->whereIn('type', ['topup', 'payout'])->where('status', 'pending')->orderBy('id')->get();
        $recent  = DB::table(TravoWallet::ENTRIES)->orderByDesc('id')->limit(60)->get();
        $uuids   = $pending->pluck('user_uuid')->merge($recent->pluck('user_uuid'))->unique()->values();
        $users   = DB::table('users')->whereIn('uuid', $uuids)->get(['uuid', 'name', 'email', 'public_id'])->keyBy('uuid');
        $who     = fn ($e) => ['name' => optional($users->get($e->user_uuid))->name, 'email' => optional($users->get($e->user_uuid))->email];

        $refs = DB::table(TravoNetwork::REFERRALS)->orderByDesc('id')->limit(100)->get();
        $ru   = DB::table('users')->whereIn('uuid', $refs->pluck('referrer_uuid')->merge($refs->pluck('referred_uuid'))->unique())->pluck('name', 'uuid');

        return response()->json([
            'pending'   => $pending->map(fn ($e) => TravoWallet::present($e, true) + ['user' => $who($e)])->values(),
            'recent'    => $recent->map(fn ($e) => TravoWallet::present($e, true) + ['user' => $who($e)])->values(),
            'referrals' => $refs->map(fn ($r) => [
                'referrer' => $ru[$r->referrer_uuid] ?? '?', 'referred' => $ru[$r->referred_uuid] ?? '?', 'source' => $r->source,
                'signed_up_at' => $r->signed_up_at, 'expires_at' => $r->expires_at, 'status' => $r->status,
                'earned' => (int) DB::table(TravoNetwork::EARNINGS)->where('referral_id', $r->id)->whereIn('status', ['pending', 'available'])->sum('amount'),
            ])->values(),
            'totals'    => [
                'wallet_balances' => (int) DB::table(TravoWallet::ENTRIES)->where('status', 'posted')->sum('amount'),
                'commission_owed' => (int) DB::table(TravoNetwork::EARNINGS)->whereIn('status', ['pending', 'available'])->sum('amount'),
                'invites_sent'    => DB::table(TravoNetwork::INVITES)->whereNotNull('sent_at')->count(),
                'referrals'       => DB::table(TravoNetwork::REFERRALS)->where('status', 'active')->count(),
            ],
            'bank' => OrderFlowController::BANK,
        ]);
    }

    public function decide(Request $request, string $ref)
    {
        if (!$this->staff($request)) {
            return response()->json(['error' => 'Only Travo admins can do this.'], 403);
        }
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'amount' => 'nullable', 'note' => 'nullable|string|max:300']);
        $kobo = isset($data['amount']) && $data['amount'] !== '' ? $this->naira($data['amount']) : null;
        $res  = TravoWallet::decide($ref, $data['decision'], $request->user(), $kobo, $data['note'] ?? null);

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    public function adjust(Request $request)
    {
        if (!$this->staff($request)) {
            return response()->json(['error' => 'Only Travo admins can do this.'], 403);
        }
        $data = $request->validate(['user' => 'required|string|max:191', 'amount' => 'required', 'reason' => 'required|string|max:180']);
        $u    = DB::table('users')->whereNull('deleted_at')->where(fn ($q) => $q->where('email', $data['user'])->orWhere('public_id', $data['user']))->first();
        if (!$u) {
            return response()->json(['error' => 'No user with that email.'], 404);
        }
        $raw  = str_replace([',', '₦', ' '], '', (string) $data['amount']);
        if (!is_numeric($raw) || (float) $raw == 0.0) {
            return response()->json(['error' => 'Enter an amount, e.g. 2500 or -2500.'], 422);
        }
        $e = TravoWallet::adjust($u->uuid, (int) round(((float) $raw) * 100), $data['reason'], $request->user());

        return response()->json(['ok' => true, 'entry' => TravoWallet::present($e, true), 'balances' => TravoWallet::balances($u->uuid)]);
    }
}
