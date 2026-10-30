<?php

namespace App\Console\Commands;

use App\Support\TravoNetwork;
use App\Support\TravoWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Travo My Network + Wallet. Examples:
 *   php artisan travo:network --install                    create tables + settings (sends nothing)
 *   php artisan travo:network --status
 *   php artisan travo:network --dry                        what the next run would do
 *   php artisan travo:network --run                        the 10-minute job (host cron)
 *   php artisan travo:network --test-invite=martin@nwike.com --user=martin@nwike.com   one invite + one reminder email to that address
 *   php artisan travo:network --set=invites_enabled=false
 *   php artisan travo:network --link-user=<new user email> --code=<code>   staff: link an account by hand (same rules)
 */
class TravoNetworkCommand extends Command
{
    protected $signature = 'travo:network {--install} {--status} {--run} {--dry} {--test-invite=} {--user=} {--set=*} {--link-user=} {--code=}';

    protected $description = 'Travo My Network (invites, referrals, commission) and wallet';

    public function handle(): int
    {
        if ($this->option('install')) {
            TravoNetwork::install();
            $this->info('installed; settings: ' . TravoNetwork::settingsPath());
            $this->line(json_encode(TravoNetwork::settings(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        foreach ((array) $this->option('set') as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, null);
            if (!array_key_exists($k, TravoNetwork::DEFAULTS)) {
                $this->error("unknown setting $k");

                return 1;
            }
            $s     = TravoNetwork::settings();
            $s[$k] = is_numeric($v) ? $v + 0 : ($v === 'true' ? true : ($v === 'false' ? false : $v));
            TravoNetwork::saveSettings($s);
            $this->info("$k = " . json_encode($s[$k]));
        }

        if ($to = $this->option('test-invite')) {
            $who = $this->option('user') ?: $to;
            $u   = DB::table('users')->where('email', $who)->orWhere('public_id', $who)->first();
            if (!$u) {
                $this->error('no inviter; pass --user=<email>');

                return 1;
            }
            $inv = (object) ['id' => 0, 'inviter_uuid' => $u->uuid, 'email' => $to, 'name' => 'Martin', 'token' => 'test-' . substr(md5($to), 0, 10)];
            foreach ([false, true] as $rem) {
                $r = TravoNetwork::sendInviteEmail($inv, $rem, $to);
                $this->line(($r === true ? 'sent ' : 'FAILED ' . $r . ' ') . ($rem ? 'reminder' : 'invite') . ' to ' . $to);
            }
        }

        if ($email = $this->option('link-user')) {
            $u = DB::table('users')->where('email', $email)->first();
            if (!$u || !$this->option('code')) {
                $this->error('need --link-user=<email> and --code=<code>');

                return 1;
            }
            $this->line(json_encode(TravoNetwork::claim($u, $this->option('code'), null, 'staff')));
        }

        if ($this->option('dry')) {
            $this->line(json_encode(TravoNetwork::run(true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        if ($this->option('run')) {
            $this->line(now()->toDateTimeString() . ' ' . json_encode(TravoNetwork::run(false)));
        }

        if ($this->option('status')) {
            $this->line('settings: ' . json_encode(array_intersect_key(TravoNetwork::settings(), array_flip(['enabled', 'invites_enabled', 'launch_at', 'commission_pct', 'months']))));
            $this->line('codes: ' . DB::table(TravoNetwork::CODES)->count() . ', clicks: ' . (int) DB::table(TravoNetwork::CODES)->sum('clicks'));
            foreach (DB::table(TravoNetwork::INVITES)->select('status', DB::raw('count(*) n'))->groupBy('status')->get() as $r) {
                $this->line('  invites ' . str_pad($r->status, 10) . $r->n);
            }
            $this->line('referrals: ' . DB::table(TravoNetwork::REFERRALS)->where('status', 'active')->count());
            foreach (DB::table(TravoNetwork::EARNINGS)->where('amount', '>', 0)->select('status', DB::raw('count(*) n'), DB::raw('sum(amount) a'))->groupBy('status')->get() as $r) {
                $this->line('  commission ' . str_pad($r->status, 10) . $r->n . '  ' . TravoWallet::money((int) $r->a));
            }
            $this->line('wallet balances (posted): ' . TravoWallet::money((int) DB::table(TravoWallet::ENTRIES)->where('status', 'posted')->sum('amount'))
                . ', pending top-ups: ' . DB::table(TravoWallet::ENTRIES)->where('type', 'topup')->where('status', 'pending')->count()
                . ', pending payouts: ' . DB::table(TravoWallet::ENTRIES)->where('type', 'payout')->where('status', 'pending')->count());
        }

        return 0;
    }
}
