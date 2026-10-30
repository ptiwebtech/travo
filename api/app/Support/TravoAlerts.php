<?php

namespace App\Support;

use App\Http\Controllers\OrderFlowController;
use App\Http\Controllers\QuoteShareController;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Travo alerts: entries in the console's Alerts box (the bell in the header), written to Laravel's
 * `notifications` table, which the console reads (unread, newest 20) on every page load.
 *
 * What gets an alert (only things that happen after the alerts were switched on - no back-fill):
 *  - a new account: "Welcome to Travo" (opens the getting-started guide in the console)
 *  - orders the user booked: booked, payment received, dispatched / collected, delivered, canceled
 *  - wallet movements: top-up requested / received / not found, paid from wallet, refunds,
 *    cash-out requested / paid / declined, network commission earned / available / reversed, corrections
 *
 * Every alert has a unique key (travo_alert_keys) so a re-run never sends the same alert twice.
 * Runs from the host cron every minute: php artisan travo:alerts --run
 */
class TravoAlerts
{
    public const KEYS = 'travo_alert_keys';
    public const FILE = 'travo-alerts.json';
    public const BANK = ['name' => 'Travo Services Limited', 'bank' => 'OPay', 'number' => '6420527380'];

    public static function settings(): array
    {
        $p = storage_path('app/' . self::FILE);
        $s = is_file($p) ? (json_decode((string) file_get_contents($p), true) ?: []) : [];

        return array_merge(['enabled' => true, 'launch' => null, 'cursor' => null], $s);
    }

    public static function save(array $s): void
    {
        file_put_contents(storage_path('app/' . self::FILE), json_encode(array_merge(self::settings(), $s), JSON_PRETTY_PRINT));
    }

    public static function install(): void
    {
        if (!Schema::hasTable(self::KEYS)) {
            Schema::create(self::KEYS, function ($t) {
                $t->string('key', 150)->primary();
                $t->char('user_uuid', 36)->index();
                $t->dateTime('created_at')->nullable();
            });
        }
        $s = self::settings();
        if (!$s['launch']) {
            $now = now()->toDateTimeString();
            self::save(['launch' => $now, 'cursor' => $now]);
        }
    }

    /** Write one alert, once per key. Returns true if it was written now. */
    public static function send(string $userUuid, string $key, string $subject, string $message, array $extra = [], $at = null): bool
    {
        $at = $at ?: now();
        if (!$userUuid || !DB::table('users')->where('uuid', $userUuid)->whereNull('deleted_at')->exists()) {
            return false;
        }
        $ins = DB::table(self::KEYS)->insertOrIgnore(['key' => Str::limit($key, 150, ''), 'user_uuid' => $userUuid, 'created_at' => now()]);
        if (!$ins) {
            return false;
        }
        DB::table('notifications')->insert([
            'id'              => (string) Str::uuid(),
            'type'            => 'App\\Notifications\\TravoAlert',
            'notifiable_type' => 'Fleetbase\\Models\\User',
            'notifiable_id'   => $userUuid,
            'data'            => json_encode(['subject' => $subject, 'message' => $message, 'travo' => array_merge(['key' => $key], $extra)], JSON_UNESCAPED_UNICODE),
            'created_at'      => $at,
            'updated_at'      => $at,
        ]);

        return true;
    }

    public static function ref(string $publicId): string
    {
        return 'TRV-' . strtoupper(preg_replace('/^order_/', '', $publicId));
    }

    // ---- welcome ------------------------------------------------------------------------------

    public static function welcome(object $user): bool
    {
        $first = trim(explode(' ', trim((string) $user->name))[0] ?? '');

        return self::send(
            $user->uuid,
            'welcome:' . $user->uuid,
            '🎉 Welcome to Travo' . ($first ? ', ' . $first : '') . '!',
            'Thank you for signing up. Tap here for a quick tour: how to book, pay, track and earn 1% by sharing Travo.',
            ['kind' => 'welcome', 'url' => '#welcome']
        );
    }

    // ---- orders -------------------------------------------------------------------------------

    /** Who hears about an order: whoever booked it, else the customer's own account. */
    public static function orderUser(Order $order): ?string
    {
        foreach ([$order->created_by_uuid, $order->customer_uuid] as $u) {
            if ($u && DB::table('users')->where('uuid', $u)->whereNull('deleted_at')->exists()) {
                return $u;
            }
        }

        return null;
    }

    public static function order(Order $order, string $launch): int
    {
        $user = self::orderUser($order);
        if (!$user) {
            return 0;
        }
        $pid   = $order->public_id;
        $ref   = self::ref($pid);
        $state = OrderFlowController::state($order);
        $n     = 0;
        $url   = '/fleet-ops/' . $pid;
        $q     = null;
        $quote = function () use (&$q, $order) {
            if ($q === null) {
                try {
                    $order->loadMissing(['payload.pickup', 'payload.dropoff', 'payload.entities', 'purchaseRate.serviceQuote.items']);
                    $q = app(QuoteShareController::class)->buildQuote($order, QuoteShareController::code($order->public_id)) ?: [];
                } catch (\Throwable $e) {
                    $q = [];
                }
            }

            return $q;
        };

        if ($order->created_at && $order->created_at->toDateTimeString() >= $launch) {
            $qq    = $quote();
            $what  = preg_replace('/^[A-Z &]+ - /', '', trim((string) ($qq['orderType'] ?? 'Your booking')));
            $route = trim(implode(' → ', array_filter([data_get($qq, 'pickup.label'), data_get($qq, 'dropoff.label')])));
            $amt   = isset($qq['amount']) && $qq['amount'] ? TravoWallet::money((int) $qq['amount']) : null;
            $msg   = $what . ($route ? ' · ' . $route : '') . '.' . ($amt ? ' Total ' . $amt . '.' : '')
                . ' To confirm it, pay by bank transfer to ' . self::BANK['bank'] . ' ' . self::BANK['number'] . ' (' . self::BANK['name'] . ') with reference ' . $ref . ', or pay from your Travo wallet.';
            $n += self::send($user, "ord:$pid:booked", '📦 Booking received: ' . $ref, $msg, ['kind' => 'order', 'url' => $url, 'order' => $pid]) ? 1 : 0;
        }
        if (!empty($state['canceled'])) {
            $n += self::send($user, "ord:$pid:canceled", 'Order ' . $ref . ' was canceled',
                'This booking has been canceled. Anything you paid from your Travo wallet goes back to your wallet within 10 minutes. Questions? Message us in Chat.',
                ['kind' => 'order', 'url' => $url, 'order' => $pid]) ? 1 : 0;

            return $n;
        }
        $done = [];
        foreach ((array) ($state['steps'] ?? []) as $s) {
            if (!empty($s['done'])) {
                $done[$s['key']] = $s['at'] ?? null;
            }
        }
        $msgs = [
            'paid'       => ['✅ Payment received: ' . $ref, 'Thank you! Your booking is confirmed. We will let you know as soon as it is on its way.'],
            'dispatched' => ['🚚 On its way: ' . $ref, 'Your order has been dispatched / collected. Open it to follow its progress and share tracking with anyone.'],
            'delivered'  => ['🎉 Delivered: ' . $ref, 'Delivered! Thank you for using Travo. Know someone who could use us? Share your link from My Network and earn 1% of their bookings.'],
        ];
        foreach ($msgs as $k => [$subj, $msg]) {
            if (!array_key_exists($k, $done)) {
                continue;
            }
            // only steps reached after the alerts went live (no back-fill of old progress)
            $at = $done[$k] ? \Carbon\Carbon::parse($done[$k])->toDateTimeString() : null;
            if ($at && $at < $launch) {
                continue;
            }
            if (!$at && $order->updated_at && $order->updated_at->toDateTimeString() < $launch) {
                continue;
            }
            $n += self::send($user, "ord:$pid:$k", $subj, $msg, ['kind' => 'order', 'url' => $url, 'order' => $pid]) ? 1 : 0;
        }

        return $n;
    }

    // ---- wallet -------------------------------------------------------------------------------

    public static function wallet(object $e): int
    {
        $amt  = TravoWallet::money(abs((int) $e->amount));
        $ref  = $e->public_id;
        $ord  = $e->order_public_id ? self::ref($e->order_public_id) : null;
        $url  = '#wallet';
        $card = (data_get(json_decode((string) $e->meta, true), 'method') === 'card');
        $m    = null;
        switch ($e->type . ':' . $e->status) {
            case 'topup:pending':
                if ($card) {
                    return 0;   // card top-ups only matter once paid
                }
                $m = ['💳 Top-up request ' . $ref, 'Send ' . $amt . ' by bank transfer to ' . self::BANK['bank'] . ' ' . self::BANK['number'] . ' (' . self::BANK['name'] . ') with reference ' . $ref . '. We add it to your wallet as soon as it arrives.'];
                break;
            case 'topup:posted':
                $m = ['💰 ' . $amt . ' added to your wallet', 'Your top-up ' . $ref . ' has arrived. Use your wallet to pay for bookings in full or in part.'];
                break;
            case 'topup:rejected':
                $m = ['Top-up ' . $ref . ' not found', 'We could not find a transfer for ' . $ref . '. If you have paid, send us the receipt in Chat and we will sort it out.'];
                break;
            case 'order:posted':
                $m = ['🧾 ' . $amt . ' paid from your wallet', 'Payment for ' . ($ord ?: 'your booking') . ' from your Travo wallet. Reference ' . $ref . '.'];
                break;
            case 'refund:posted':
                $m = ['↩️ ' . $amt . ' refunded to your wallet', 'The money you paid from your wallet for ' . ($ord ?: 'a canceled booking') . ' is back in your wallet.'];
                break;
            case 'payout:pending':
                $m = ['🏦 Cash-out request ' . $ref, 'We have your request to cash out ' . $amt . '. We will let you know when it is paid into your bank account.'];
                break;
            case 'payout:posted':
                $m = ['🏦 ' . $amt . ' paid to your bank', 'Your cash-out ' . $ref . ' has been paid.'];
                break;
            case 'payout:rejected':
                $m = ['Cash-out ' . $ref . ' declined', 'Your cash-out could not be paid and the money is back in your wallet. Message us in Chat for details.'];
                break;
            case 'commission:pending':
                $m = ['🤝 You earned ' . $amt . ' from your network', 'Someone you invited made a booking. Your 1% commission will be available in your wallet after 14 days or once it is delivered.'];
                break;
            case 'commission:posted':
                $m = ['🤝 ' . $amt . ' commission now in your wallet', 'Your network commission is ready to use for bookings or cash out.'];
                break;
            case 'commission:reversed':
                $m = ['Commission ' . $amt . ' reversed', 'A booking from your network was canceled, so its commission was reversed.'];
                break;
            case 'adjustment:posted':
                $m = ['Wallet ' . ((int) $e->amount >= 0 ? 'credit' : 'correction') . ': ' . ((int) $e->amount >= 0 ? '+' : '−') . $amt, trim((string) $e->description) ?: 'A correction was made to your wallet.'];
                break;
        }
        if (!$m) {
            return 0;
        }

        return self::send($e->user_uuid, 'wal:' . $ref . ':' . $e->status, $m[0], $m[1], ['kind' => 'wallet', 'url' => $url, 'entry' => $ref]) ? 1 : 0;
    }

    // ---- the minute job -----------------------------------------------------------------------

    public static function run(bool $dry = false): array
    {
        self::install();
        $s = self::settings();
        if (!$s['enabled']) {
            return ['skipped' => 'disabled'];
        }
        $launch = $s['launch'];
        $since  = \Carbon\Carbon::parse($s['cursor'] ?: $launch)->subMinutes(2)->toDateTimeString();   // overlap: keys stop repeats
        $start  = now()->toDateTimeString();
        $out    = ['welcome' => 0, 'order' => 0, 'wallet' => 0, 'since' => $since];

        if ($dry) {
            $out['users']   = DB::table('users')->where('created_at', '>=', max($since, $launch))->whereNull('deleted_at')->count();
            $out['orders']  = DB::table('orders')->where('updated_at', '>=', $since)->count();
            $out['entries'] = Schema::hasTable(TravoWallet::ENTRIES) ? DB::table(TravoWallet::ENTRIES)->where('updated_at', '>=', $since)->count() : 0;

            return $out;
        }

        foreach (DB::table('users')->where('created_at', '>=', max($since, $launch))->whereNull('deleted_at')->get() as $u) {
            try { $out['welcome'] += self::welcome($u) ? 1 : 0; } catch (\Throwable $e) { report($e); }
        }

        $ids = DB::table('orders')->where('updated_at', '>=', $since)->whereNull('deleted_at')->pluck('uuid')->all();
        $trk = DB::table('tracking_statuses')->where('created_at', '>=', $since)->pluck('tracking_number_uuid')->filter()->all();
        if ($trk) {
            $ids = array_merge($ids, DB::table('orders')->whereIn('tracking_number_uuid', $trk)->pluck('uuid')->all());
        }
        foreach (array_slice(array_values(array_unique($ids)), 0, 300) as $uuid) {
            try {
                $o = Order::where('uuid', $uuid)->first();
                if ($o) {
                    $out['order'] += self::order($o, $launch);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (Schema::hasTable(TravoWallet::ENTRIES)) {
            foreach (DB::table(TravoWallet::ENTRIES)->where('updated_at', '>=', max($since, $launch))->orderBy('id')->limit(500)->get() as $e) {
                try { $out['wallet'] += self::wallet($e); } catch (\Throwable $ex) { report($ex); }
            }
        }

        self::save(['cursor' => $start]);

        return $out;
    }
}
