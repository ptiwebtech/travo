<?php

namespace App\Support;

use App\Http\Controllers\OrderFlowController;
use App\Http\Controllers\QuoteShareController;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Card payments with Quickteller (Interswitch Web Checkout, redirect), 27 Oct 2026.
 *
 *  - Orders: https://travo.ng/pay/{quote code} (the same signed code as the quote link), so it works from the
 *    quote page, quote emails, the tracking page and the console, with or without signing in.
 *    A successful card payment is credited to the order owner's Travo wallet and immediately used to pay the order
 *    (TravoWallet::payOrder), so part payments, refunds, emails and the tracking page all keep working as they are.
 *  - Wallet top-ups from the console: credited automatically once the payment is confirmed.
 *  - Money is only given after the server re-checks the payment with Interswitch (gettransaction.json):
 *    response code 00 AND the exact amount. The browser redirect alone is never trusted.
 *    A 5-minute job re-checks payments whose customer closed the page before coming back.
 *
 * Settings: storage/app/travo-card.json (merchant_code, pay_item_id: public identifiers, not secrets).
 * Table: travo_card_payments.
 */
class TravoCard
{
    public const TABLE = 'travo_card_payments';

    public const PAY_BASE = 'https://travo.ng/pay/';

    public const DEFAULTS = [
        'enabled'       => false,
        'mode'          => 'live',           // live | test
        'merchant_code' => null,
        'pay_item_id'   => null,
        'min_topup'     => 100000,           // ₦1,000 (kobo)
        'max_amount'    => 500000000,        // ₦5,000,000 (kobo) per card payment
        'notify_staff'  => true,
    ];

    public static function settings(): array
    {
        $p = storage_path('app/travo-card.json');
        $s = is_file($p) ? json_decode((string) file_get_contents($p), true) : [];

        return array_merge(self::DEFAULTS, is_array($s) ? $s : []);
    }

    public static function saveSettings(array $s): void
    {
        file_put_contents(storage_path('app/travo-card.json'), json_encode(array_merge(self::settings(), $s), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function ready(): bool
    {
        $s = self::settings();

        return $s['enabled'] && $s['merchant_code'] && $s['pay_item_id'];
    }

    public static function install(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function ($t) {
                $t->id();
                $t->string('txn_ref', 40)->unique();
                $t->string('purpose', 10);                     // order | topup
                $t->string('user_uuid', 64)->nullable()->index();
                $t->string('order_uuid', 64)->nullable()->index();
                $t->string('order_public_id', 64)->nullable();
                $t->bigInteger('amount');                      // kobo
                $t->string('status', 12)->default('pending');  // pending | paid | failed | mismatch
                $t->string('resp_code', 10)->nullable();
                $t->string('resp_desc', 190)->nullable();
                $t->string('payment_ref', 80)->nullable();
                $t->string('card', 30)->nullable();
                $t->string('wallet_ref', 40)->nullable();
                $t->string('email', 190)->nullable();
                $t->text('meta')->nullable();
                $t->timestamp('checked_at')->nullable();
                $t->timestamp('paid_at')->nullable();
                $t->timestamps();
            });
        }
    }

    // ---- urls ----------------------------------------------------------------------------------

    public static function orderUrl(string $publicId): string
    {
        return self::PAY_BASE . QuoteShareController::code($publicId);
    }

    private static function sig(string $txn): string
    {
        return substr(hash_hmac('sha256', 'travo-card|' . $txn, (string) config('app.key')), 0, 24);
    }

    public static function goUrl(string $txn): string
    {
        return self::PAY_BASE . 'go/' . $txn . '/' . self::sig($txn);
    }

    public static function checkSig(string $txn, string $sig): bool
    {
        return hash_equals(self::sig($txn), $sig);
    }

    public static function returnUrl(): string
    {
        return self::PAY_BASE . 'return';
    }

    private static function host(): string
    {
        return self::settings()['mode'] === 'test' ? 'https://newwebpay-sandbox.interswitchng.com' : 'https://newwebpay.interswitchng.com';
    }

    private static function queryHost(): string
    {
        return self::settings()['mode'] === 'test' ? 'https://sandbox.interswitchng.com' : 'https://webpay.interswitchng.com';
    }

    // ---- orders ---------------------------------------------------------------------------------

    public static function orderFromCode(string $code): ?Order
    {
        $publicId = strlen($code) > 13 && $code[strlen($code) - 13] === '-' ? substr($code, 0, -13) : '';
        if (!$publicId || !hash_equals(QuoteShareController::code($publicId), $code)) {
            return null;
        }

        return Order::where('public_id', $publicId)->whereNull('deleted_at')
            ->with(['payload.pickup', 'payload.dropoff', 'payload.entities', 'purchaseRate.serviceQuote.items'])->first();
    }

    /** The user whose wallet receives the card money for an order (the customer, else whoever booked it). */
    public static function orderOwner(Order $order): ?object
    {
        foreach ([$order->customer_uuid, $order->created_by_uuid] as $u) {
            if ($u && ($row = DB::table('users')->where('uuid', $u)->whereNull('deleted_at')->first())) {
                return $row;
            }
        }

        return null;
    }

    /** What the pay page shows: total, paid so far, left to pay, whether it can be paid by card now. */
    public static function orderState(Order $order): array
    {
        $state = OrderFlowController::state($order);
        $q     = app(QuoteShareController::class)->buildQuote($order, QuoteShareController::code($order->public_id));
        $due   = $q['amount'] ?? null;
        $paid  = TravoWallet::walletPaid($order->uuid);
        $left  = $due ? max(0, $due - $paid) : null;
        $why   = null;
        if ($state['canceled']) {
            $why = 'This order was canceled.';
        } elseif (($state['next']['key'] ?? null) !== 'paid') {
            $why = 'paid';
        } elseif (!$due) {
            $why = 'This order does not have a price yet. Message us on WhatsApp and we will help.';
        } elseif (!self::orderOwner($order)) {
            $why = 'This order cannot be paid online. Please pay by bank transfer below.';
        } elseif ($left <= 0) {
            $why = 'paid';
        }

        return ['q' => $q, 'due' => $due, 'paid' => $paid, 'left' => $left, 'ref' => OrderFlowController::paymentRef($order), 'blocked' => $why];
    }

    // ---- start a payment ------------------------------------------------------------------------

    private static function newRef(): string
    {
        do {
            $ref = 'TRVC' . strtoupper(Str::random(10));
        } while (DB::table(self::TABLE)->where('txn_ref', $ref)->exists());

        return $ref;
    }

    public static function startOrder(Order $order, ?string $email = null): array
    {
        if (!self::ready()) {
            return ['error' => 'Card payments are not available yet. Please pay by bank transfer.'];
        }
        $st = self::orderState($order);
        if ($st['blocked']) {
            return ['error' => $st['blocked'] === 'paid' ? 'This order has already been paid.' : $st['blocked']];
        }
        if ($st['left'] > self::settings()['max_amount']) {
            return ['error' => 'This amount is too large for a card payment. Please pay by bank transfer.'];
        }
        $owner = self::orderOwner($order);
        $ref   = self::newRef();
        DB::table(self::TABLE)->insert([
            'txn_ref' => $ref, 'purpose' => 'order', 'user_uuid' => $owner->uuid, 'order_uuid' => $order->uuid,
            'order_public_id' => $order->public_id, 'amount' => $st['left'], 'status' => 'pending',
            'email' => $email ?: $owner->email, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['ok' => true, 'txn' => $ref];
    }

    public static function startTopup(object $user, int $kobo): array
    {
        $s = self::settings();
        if (!self::ready()) {
            return ['error' => 'Card top-ups are not available yet. Please top up by bank transfer.'];
        }
        if ($kobo < $s['min_topup']) {
            return ['error' => 'The smallest card top-up is ' . TravoWallet::money((int) $s['min_topup']) . '.'];
        }
        if ($kobo > $s['max_amount']) {
            return ['error' => 'That is more than one card payment can take. Please top up by bank transfer.'];
        }
        $ref = self::newRef();
        DB::table(self::TABLE)->insert([
            'txn_ref' => $ref, 'purpose' => 'topup', 'user_uuid' => $user->uuid, 'amount' => $kobo, 'status' => 'pending',
            'email' => $user->email, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['ok' => true, 'txn' => $ref, 'url' => self::goUrl($ref)];
    }

    /** The auto-submitting form that sends the customer to Quickteller. */
    public static function checkoutForm(object $p): array
    {
        $s    = self::settings();
        $user = $p->user_uuid ? DB::table('users')->where('uuid', $p->user_uuid)->first(['name', 'email']) : null;

        return [
            'action' => self::host() . '/collections/w/pay',
            'fields' => [
                'merchant_code'     => $s['merchant_code'],
                'pay_item_id'       => $s['pay_item_id'],
                'txn_ref'           => $p->txn_ref,
                'amount'            => (int) $p->amount,
                'currency'          => 566,
                'site_redirect_url' => self::returnUrl(),
                'cust_email'        => $p->email ?: optional($user)->email ?: 'hello@travoservices.com',
                'cust_name'         => Str::limit(trim((string) optional($user)->name) ?: 'Travo customer', 60, ''),
                'pay_item_name'     => $p->purpose === 'topup' ? 'Travo wallet top-up' : 'Travo order ' . $p->order_public_id,
            ],
        ];
    }

    // ---- confirm with Interswitch and give value (idempotent) -----------------------------------

    public static function confirm(string $txn): object
    {
        $p = DB::table(self::TABLE)->where('txn_ref', $txn)->first();
        // failed ones are re-checked too (a customer may finish paying after we first looked), for 3 days
        if (!$p || !in_array($p->status, ['pending', 'failed'], true) || ($p->status === 'failed' && strtotime($p->created_at) < time() - 3 * 86400)) {
            return $p ?: (object) ['status' => 'missing'];
        }
        $s = self::settings();
        $r = rescue(function () use ($s, $p) {
            return Http::timeout(20)->acceptJson()->withHeaders(['Content-Type' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36 TravoPayments/1.0'])
                ->get(self::queryHost() . '/collections/api/v1/gettransaction.json', [
                    'merchantcode' => $s['merchant_code'], 'transactionreference' => $p->txn_ref, 'amount' => (int) $p->amount,
                ])->json();
        }, null, true);
        DB::table(self::TABLE)->where('id', $p->id)->update(['checked_at' => now(), 'updated_at' => now()]);
        if (!is_array($r) || !isset($r['ResponseCode'])) {
            return DB::table(self::TABLE)->where('id', $p->id)->first();   // couldn't reach Interswitch: stays pending, re-checked later
        }
        $code = (string) $r['ResponseCode'];
        $upd  = [
            'resp_code' => Str::limit($code, 10, ''), 'resp_desc' => Str::limit((string) ($r['ResponseDescription'] ?? ''), 180),
            'payment_ref' => Str::limit((string) ($r['PaymentReference'] ?? ''), 78, ''), 'card' => Str::limit((string) ($r['CardNumber'] ?? ''), 28, ''),
            'updated_at' => now(),
        ];
        if ($code === '00') {
            if ((int) ($r['Amount'] ?? 0) !== (int) $p->amount) {
                DB::table(self::TABLE)->where('id', $p->id)->update($upd + ['status' => 'mismatch']);
                self::staff('Card payment amount mismatch ' . $p->txn_ref, ['Expected ' . TravoWallet::money((int) $p->amount) . ', Interswitch says ' . TravoWallet::money((int) ($r['Amount'] ?? 0)) . '. Nothing was credited. Please check it in Quickteller.']);

                return DB::table(self::TABLE)->where('id', $p->id)->first();
            }
            self::giveValue($p, $upd);
        } elseif (in_array($code, ['Z0', 'Z1', '09', 'T0', '10'], true)
            || ($code === 'Z25' && strtotime($p->created_at) > time() - 2 * 3600)) {
            DB::table(self::TABLE)->where('id', $p->id)->update($upd);             // still in progress / not paid yet
        } else {
            DB::table(self::TABLE)->where('id', $p->id)->update($upd + ['status' => 'failed']);
        }

        return DB::table(self::TABLE)->where('id', $p->id)->first();
    }

    private static function giveValue(object $p, array $upd): void
    {
        $result = DB::transaction(function () use ($p, $upd) {
            $row = DB::table(self::TABLE)->where('id', $p->id)->lockForUpdate()->first();
            if (!in_array($row->status, ['pending', 'failed'], true)) {
                return null;   // someone else already gave value
            }
            DB::table(TravoWallet::WALLETS)->insertOrIgnore(['user_uuid' => $row->user_uuid, 'currency' => 'NGN', 'created_at' => now(), 'updated_at' => now()]);
            DB::table(TravoWallet::WALLETS)->where('user_uuid', $row->user_uuid)->lockForUpdate()->first();
            $wref = TravoWallet::ref();
            DB::table(TravoWallet::ENTRIES)->insert([
                'public_id' => $wref, 'user_uuid' => $row->user_uuid, 'type' => 'topup', 'amount' => (int) $row->amount, 'status' => 'posted',
                'description' => $row->purpose === 'order' ? 'Card payment (Quickteller) for order ' . $row->order_public_id : 'Wallet top-up by card (Quickteller)',
                'meta' => json_encode(['method' => 'card', 'txn_ref' => $row->txn_ref, 'payment_ref' => $upd['payment_ref'] ?? null]),
                'created_by_uuid' => $row->user_uuid, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table(self::TABLE)->where('id', $row->id)->update($upd + ['status' => 'paid', 'paid_at' => now(), 'wallet_ref' => $wref]);

            return $row;
        });
        if (!$result) {
            return;
        }
        $user = \Fleetbase\Models\User::where('uuid', $result->user_uuid)->first();
        if ($result->purpose === 'order' && $user) {
            $order = Order::where('uuid', $result->order_uuid)->first();
            $pay   = $order ? rescue(fn () => TravoWallet::payOrder($user, $order, null), ['ok' => false, 'error' => 'error'], true) : ['ok' => false, 'error' => 'order gone'];
            // pays what is left (normally exactly the card amount); anything not needed stays in the wallet
            DB::table(self::TABLE)->where('id', $result->id)->update(['meta' => json_encode(['pay_order' => $pay])]);
            if (empty($pay['ok'])) {
                self::staff('Card payment received but order not marked paid: ' . $result->order_public_id, [
                    TravoWallet::money((int) $result->amount) . ' was paid by card (' . $result->txn_ref . ') and is in the customer\'s wallet.',
                    'Paying the order from the wallet failed: ' . ($pay['error'] ?? 'unknown') . '. Please check the order.',
                ]);
            }
        }
        self::staff(($result->purpose === 'order' ? 'Card payment for ' . $result->order_public_id : 'Wallet top-up by card') . ': ' . TravoWallet::money((int) $result->amount), [
            'Customer: ' . optional($user)->name . ' <' . optional($user)->email . '>',
            'Quickteller reference: ' . $result->txn_ref . ' / ' . ($upd['payment_ref'] ?? ''),
            $result->purpose === 'order' ? 'The order was moved to "Payment received" automatically and the customer was emailed.' : 'The wallet was credited automatically.',
        ]);
    }

    private static function staff(string $subject, array $lines): void
    {
        if (self::settings()['notify_staff']) {
            TravoNetwork::notifyStaff($subject, $lines);
        }
    }

    /** 5-minute job: re-check payments whose customer didn't come back to the site. */
    public static function recheck(): array
    {
        $out = ['checked' => 0, 'paid' => 0];
        $rows = DB::table(self::TABLE)->where('status', 'pending')->where('created_at', '>', now()->subDays(3))
            ->where('created_at', '<', now()->subMinutes(2))
            ->where(function ($q) { $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinutes(10)); })
            ->orderBy('id')->limit(30)->get();
        foreach ($rows as $p) {
            $out['checked']++;
            if (self::confirm($p->txn_ref)->status === 'paid') {
                $out['paid']++;
            }
        }
        // give up on very old pending ones
        DB::table(self::TABLE)->where('status', 'pending')->where('created_at', '<', now()->subDays(3))->update(['status' => 'failed', 'resp_desc' => 'not completed', 'updated_at' => now()]);

        return $out;
    }
}
