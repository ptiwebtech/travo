<?php

namespace App\Support;

use App\Http\Controllers\OrderFlowController;
use App\Http\Controllers\QuoteShareController;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Travo wallet (27 Oct 2026). One wallet per user, in naira, kept as a ledger.
 *
 * Every movement is a row in travo_wallet_entries (amounts in kobo, + in / - out):
 *   commission   referral commission (pending until the order is safe, then posted)      +
 *   topup        prepayment by bank transfer (pending until staff confirm the money arrived) +
 *   order        an order paid from the wallet (posted immediately)                          -
 *   payout       cash-out to the user's bank (reserved while pending, posted when paid)       -
 *   refund       wallet money paid towards an order that was then canceled                        +
 *   adjustment   staff correction (+/-)
 *
 * Available balance = posted entries + pending payouts (money already asked for can't be spent twice).
 * All balance-changing actions lock the user's travo_wallets row, so two requests can't spend the same naira.
 */
class TravoWallet
{
    public const WALLETS = 'travo_wallets';

    public const ENTRIES = 'travo_wallet_entries';

    public static function install(): void
    {
        if (!Schema::hasTable(self::WALLETS)) {
            Schema::create(self::WALLETS, function ($t) {
                $t->id();
                $t->char('user_uuid', 36)->unique();
                $t->string('currency', 3)->default('NGN');
                $t->timestamps();
            });
        }
        if (!Schema::hasTable(self::ENTRIES)) {
            Schema::create(self::ENTRIES, function ($t) {
                $t->id();
                $t->string('public_id', 20)->unique();         // reference shown to users, e.g. WAL-7K2Q9XHD
                $t->char('user_uuid', 36)->index();
                $t->string('type', 20)->index();               // commission | topup | order | payout | adjustment
                $t->bigInteger('amount');                      // kobo, signed
                $t->string('currency', 3)->default('NGN');
                $t->string('status', 20)->index();             // pending | posted | rejected | reversed
                $t->string('description', 191)->nullable();
                $t->char('order_uuid', 36)->nullable()->index();
                $t->string('order_public_id', 40)->nullable();
                $t->unsignedBigInteger('earning_id')->nullable()->index();
                $t->json('meta')->nullable();                  // payout bank details, top-up method, notes
                $t->char('created_by_uuid', 36)->nullable();
                $t->char('handled_by_uuid', 36)->nullable();
                $t->dateTime('posted_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public static function ref(): string
    {
        return 'WAL-' . strtoupper(Str::random(8));
    }

    /** Locks (and creates if needed) the user's wallet row. Call inside a DB transaction. */
    private static function lock(string $userUuid): object
    {
        DB::table(self::WALLETS)->insertOrIgnore(['user_uuid' => $userUuid, 'currency' => 'NGN', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table(self::WALLETS)->where('user_uuid', $userUuid)->lockForUpdate()->first();
    }

    public static function balances(string $userUuid): array
    {
        $q = fn () => DB::table(self::ENTRIES)->where('user_uuid', $userUuid);

        $posted          = (int) $q()->where('status', 'posted')->sum('amount');
        $reserved        = (int) $q()->where('status', 'pending')->where('type', 'payout')->sum('amount');   // negative
        $pendingIn       = (int) $q()->where('status', 'pending')->where('amount', '>', 0)->sum('amount');
        $commissionTotal = (int) $q()->where('type', 'commission')->whereIn('status', ['posted', 'pending'])->sum('amount');

        return [
            'balance'          => $posted,
            'available'        => $posted + $reserved,
            'pending_in'       => $pendingIn,          // commissions not yet released + top-ups awaiting confirmation
            'commission_total' => $commissionTotal,
            'currency'         => 'NGN',
        ];
    }

    public static function money(int $kobo): string
    {
        $v = $kobo / 100;

        return ($v < 0 ? '-' : '') . '₦' . number_format(abs($v), fmod(abs($v), 1.0) == 0.0 ? 0 : 2);
    }

    // ---- top-ups ------------------------------------------------------------------------------

    public static function requestTopup(object $user, int $kobo): object
    {
        $ref = self::ref();
        DB::table(self::ENTRIES)->insert([
            'public_id' => $ref, 'user_uuid' => $user->uuid, 'type' => 'topup', 'amount' => $kobo, 'status' => 'pending',
            'description' => 'Wallet top-up by bank transfer', 'meta' => json_encode(['method' => 'bank_transfer']),
            'created_by_uuid' => $user->uuid, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table(self::ENTRIES)->where('public_id', $ref)->first();
    }

    // ---- staff decisions (top-ups, payouts) ---------------------------------------------------

    /** $decision: approve | reject. Top-up approve = money received (posts the credit). Payout approve = paid out. */
    public static function decide(string $publicId, string $decision, object $staff, ?int $amountKobo = null, ?string $note = null): array
    {
        return DB::transaction(function () use ($publicId, $decision, $staff, $amountKobo, $note) {
            $e = DB::table(self::ENTRIES)->where('public_id', $publicId)->first();
            if (!$e || $e->status !== 'pending' || !in_array($e->type, ['topup', 'payout'], true)) {
                return ['ok' => false, 'error' => 'This request is not pending.'];
            }
            self::lock($e->user_uuid);
            $meta = json_decode((string) $e->meta, true) ?: [];
            if ($note) {
                $meta['staff_note'] = Str::limit($note, 300);
            }
            $update = ['handled_by_uuid' => $staff->uuid, 'updated_at' => now(), 'meta' => json_encode($meta)];
            if ($decision === 'approve') {
                // top-ups: staff may correct the amount to what actually arrived
                if ($e->type === 'topup' && $amountKobo !== null && $amountKobo > 0) {
                    $meta['requested_amount'] = $e->amount;
                    $update['amount']         = $amountKobo;
                    $update['meta']           = json_encode($meta);
                }
                $update += ['status' => 'posted', 'posted_at' => now()];
            } else {
                $update['status'] = 'rejected';
            }
            DB::table(self::ENTRIES)->where('id', $e->id)->update($update);

            return ['ok' => true];
        });
    }

    public static function adjust(string $userUuid, int $kobo, string $reason, object $staff): object
    {
        return DB::transaction(function () use ($userUuid, $kobo, $reason, $staff) {
            self::lock($userUuid);
            $ref = self::ref();
            DB::table(self::ENTRIES)->insert([
                'public_id' => $ref, 'user_uuid' => $userUuid, 'type' => 'adjustment', 'amount' => $kobo, 'status' => 'posted',
                'description' => Str::limit($reason, 180), 'created_by_uuid' => $staff->uuid, 'handled_by_uuid' => $staff->uuid,
                'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table(self::ENTRIES)->where('public_id', $ref)->first();
        });
    }

    // ---- payouts ------------------------------------------------------------------------------

    public static function requestPayout(object $user, int $kobo, array $bank, int $minimum): array
    {
        return DB::transaction(function () use ($user, $kobo, $bank, $minimum) {
            self::lock($user->uuid);
            $b = self::balances($user->uuid);
            if ($kobo < $minimum) {
                return ['ok' => false, 'error' => 'The minimum payout is ' . self::money($minimum) . '.'];
            }
            if ($kobo > $b['available']) {
                return ['ok' => false, 'error' => 'You can withdraw up to ' . self::money(max(0, $b['available'])) . '.'];
            }
            $ref = self::ref();
            DB::table(self::ENTRIES)->insert([
                'public_id' => $ref, 'user_uuid' => $user->uuid, 'type' => 'payout', 'amount' => -$kobo, 'status' => 'pending',
                'description' => 'Payout to ' . $bank['bank'] . ' ••••' . substr($bank['number'], -4),
                'meta' => json_encode(['bank' => $bank]), 'created_by_uuid' => $user->uuid, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['ok' => true, 'ref' => $ref];
        });
    }

    // ---- paying an order from the wallet ----------------------------------------------------

    /** Who may pay this order from their wallet: its creator, its customer, or a member of its company. */
    public static function canPay(object $user, Order $order): bool
    {
        return $order->created_by_uuid === $user->uuid
            || $order->customer_uuid === $user->uuid
            || (!(method_exists($user, 'isAdmin') && $user->isAdmin()) && ($order->company_uuid === $user->company_uuid
            || DB::table('company_users')->where('user_uuid', $user->uuid)->where('company_uuid', $order->company_uuid)->whereNull('deleted_at')->exists()));
    }

    /** Amount due for an order (kobo) or null if it has no price. */
    public static function amountDue(Order $order): ?int
    {
        $order->loadMissing(['payload.pickup', 'payload.dropoff', 'payload.entities', 'purchaseRate.serviceQuote.items']);
        $q = app(QuoteShareController::class)->buildQuote($order, QuoteShareController::code($order->public_id));

        return $q['amount'] ?? null;
    }

    /** How much of an order has been paid from wallets so far (kobo), after any refunds. */
    public static function walletPaid(string $orderUuid): int
    {
        return -(int) DB::table(self::ENTRIES)->where('order_uuid', $orderUuid)->where('status', 'posted')->whereIn('type', ['order', 'refund'])->sum('amount');
    }

    /** Wallet payments on an order, looked up by its payment reference (TRV-XXXXXXX); for the tracking page and emails. */
    public static function paymentsForRef(string $ref): int
    {
        $pid = 'ORDER_' . strtoupper(substr($ref, 4));

        return -(int) DB::table(self::ENTRIES)->whereRaw('UPPER(order_public_id) = ?', [$pid])->where('status', 'posted')->whereIn('type', ['order', 'refund'])->sum('amount');
    }

    public const MIN_PART = 10000;   // ₦100: smallest part payment (unless it settles the order)

    /**
     * Pays an order from the wallet: all of what is left ($amount null) or part of it.
     * The order moves to "Payment received" (and the customer is emailed) only when the whole amount has been paid.
     */
    public static function payOrder(object $user, Order $order, ?int $amount = null): array
    {
        $state = OrderFlowController::state($order);
        if ($state['canceled']) {
            return ['ok' => false, 'error' => 'This order is canceled.'];
        }
        if (($state['next']['key'] ?? null) !== 'paid') {
            return ['ok' => false, 'error' => 'This order has already been paid.'];
        }
        $due = self::amountDue($order);
        if (!$due || $due <= 0) {
            return ['ok' => false, 'error' => 'This order has no price yet. Contact us on WhatsApp.'];
        }

        $res = DB::transaction(function () use ($user, $order, $due, $amount) {
            self::lock($user->uuid);
            DB::table('orders')->where('uuid', $order->uuid)->lockForUpdate()->first();   // one payment on an order at a time
            $before = self::walletPaid($order->uuid);
            $left   = $due - $before;
            if ($left <= 0) {
                return ['ok' => false, 'error' => 'This order has already been paid.'];
            }
            $pay = $amount === null ? $left : $amount;
            if ($pay <= 0) {
                return ['ok' => false, 'error' => 'Enter the amount you want to pay.'];
            }
            if ($pay > $left) {
                return ['ok' => false, 'error' => 'Only ' . self::money($left) . ' is left to pay on this order.'];
            }
            if ($pay < self::MIN_PART && $pay < $left) {
                return ['ok' => false, 'error' => 'The smallest part payment is ' . self::money(self::MIN_PART) . '.'];
            }
            $b = self::balances($user->uuid);
            if ($b['available'] <= 0) {
                return ['ok' => false, 'error' => 'Please top up your wallet before you can pay.'];
            }
            if ($b['available'] < $pay) {
                return ['ok' => false, 'error' => 'Your wallet has ' . self::money($b['available']) . '. Choose an amount up to that, or top up first.'];
            }
            $final  = $pay === $left;
            $oref   = OrderFlowController::paymentRef($order);
            $ref    = self::ref();
            $leftAfter = $left - $pay;
            DB::table(self::ENTRIES)->insert([
                'public_id' => $ref, 'user_uuid' => $user->uuid, 'type' => 'order', 'amount' => -$pay, 'status' => 'posted',
                'description' => $final
                    ? ($before > 0 ? 'Final payment for order ' . $oref : 'Paid order ' . $oref)
                    : 'Part payment for order ' . $oref . ' (' . self::money($leftAfter) . ' left to pay)',
                'order_uuid' => $order->uuid, 'order_public_id' => $order->public_id,
                'meta' => json_encode(['order_total' => $due, 'paid_before' => $before, 'left_after' => $leftAfter]),
                'created_by_uuid' => $user->uuid, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $order->updateMeta('travo_wallet', ['paid' => $before + $pay, 'due' => $due, 'left' => $leftAfter, 'last' => $ref, 'at' => now()->toIso8601String()]);
            if ($final) {
                $details = $before > 0
                    ? 'Paid in full: ' . self::money($due) . ', including ' . self::money($before + $pay) . ' from the Travo wallet (last payment ' . $ref . ').'
                    : 'Paid in full from the Travo wallet (' . $ref . ').';
                OrderFlowController::applyStep($order, 'paid', $user, $details, ['wallet' => $ref, 'amount' => $due]);
            }

            return ['ok' => true, 'ref' => $ref, 'amount' => $pay, 'paid_total' => $before + $pay, 'left' => $leftAfter, 'final' => $final, 'order_ref' => $oref];
        });

        if ($res['ok'] && $res['final']) {
            $mail = OrderFlowController::sendStepEmail($order->fresh(), 'paid');
            if ($mail['sent']) {
                $flow                    = (array) data_get($order->fresh()->meta, 'travo_flow', []);
                $flow['paid']['emailed'] = $mail['to'];
                $order->fresh()->updateMeta('travo_flow', $flow);
            }
        } elseif ($res['ok']) {
            TravoNetwork::notifyStaff('Part payment ' . $res['ref'] . ' on ' . $res['order_ref'], [
                'Customer: ' . $user->name . ' <' . $user->email . '>',
                'Paid from wallet now: ' . self::money($res['amount']) . ' (total from wallet so far: ' . self::money($res['paid_total']) . ')',
                'Left to pay: ' . self::money($res['left']) . '. Expect the rest from the wallet or by bank transfer with reference ' . $res['order_ref'] . '.',
                'When the rest arrives by transfer, mark the order "Payment received" as usual.',
            ]);
        }

        return $res;
    }

    /** Wallet money paid towards orders that were then canceled goes back to the wallet it came from. */
    public static function refundCanceled(): int
    {
        $n    = 0;
        $rows = DB::table(self::ENTRIES . ' as e')->join('orders as o', 'o.uuid', '=', 'e.order_uuid')
            ->where('e.status', 'posted')->whereIn('e.type', ['order', 'refund'])
            ->where(function ($q) {
                $q->whereIn('o.status', ['canceled', 'cancelled'])->orWhereNotNull('o.deleted_at');
            })
            ->groupBy('e.order_uuid', 'e.user_uuid', 'e.order_public_id')
            ->havingRaw('SUM(e.amount) < 0')
            ->get([DB::raw('e.order_uuid'), DB::raw('e.user_uuid'), DB::raw('e.order_public_id'), DB::raw('SUM(e.amount) as net')]);
        foreach ($rows as $r) {
            DB::transaction(function () use ($r, &$n) {
                self::lock($r->user_uuid);
                $net = (int) DB::table(self::ENTRIES)->where('order_uuid', $r->order_uuid)->where('user_uuid', $r->user_uuid)->where('status', 'posted')->whereIn('type', ['order', 'refund'])->sum('amount');
                if ($net >= 0) {
                    return;
                }
                DB::table(self::ENTRIES)->insert([
                    'public_id' => self::ref(), 'user_uuid' => $r->user_uuid, 'type' => 'refund', 'amount' => -$net, 'status' => 'posted',
                    'description' => 'Refund: order TRV-' . strtoupper(preg_replace('/^order_/', '', (string) $r->order_public_id)) . ' was canceled',
                    'order_uuid' => $r->order_uuid, 'order_public_id' => $r->order_public_id,
                    'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $n++;
            });
        }

        return $n;
    }

    // ---- listing ------------------------------------------------------------------------------

    public static function entries(string $userUuid, int $limit = 100): array
    {
        return DB::table(self::ENTRIES)->where('user_uuid', $userUuid)->orderByDesc('id')->limit($limit)->get()->map(fn ($e) => self::present($e))->all();
    }

    public static function present(object $e, bool $staff = false): array
    {
        $meta = json_decode((string) $e->meta, true) ?: [];
        $out  = [
            'ref'         => $e->public_id,
            'type'        => $e->type,
            'amount'      => (int) $e->amount,
            'amount_text' => self::money((int) $e->amount),
            'status'      => $e->status,
            'description' => $e->description,
            'order'       => $e->order_public_id,
            'created_at'  => $e->created_at,
            'posted_at'   => $e->posted_at,
        ];
        if (!empty($meta['release_at']) && $e->status === 'pending') {
            $out['release_at'] = $meta['release_at'];
        }
        if ($staff) {
            $out['meta'] = $meta;
        }

        return $out;
    }
}
