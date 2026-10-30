<?php

namespace App\Console\Commands;

use App\Support\TravoDrip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Travo email calendar. Examples:
 *   php artisan travo:drip --install                 create tables + settings (sends nothing)
 *   php artisan travo:drip --status                  counts by status / step, today's sends
 *   php artisan travo:drip --test=martin@nwike.com   send every email once to that address (no state change)
 *   php artisan travo:drip --test=x@y.com --stage=week
 *   php artisan travo:drip --dry                     what the next run would send
 *   php artisan travo:drip --start                   switch sending on (existing users released 300/day)
 *   php artisan travo:drip --stop                    switch sending off
 *   php artisan travo:drip --set=daily_backlog=300
 *   php artisan travo:drip --run                     the hourly job (host cron)
 *   php artisan travo:drip --bounces                 read the bounce mailbox now (needs TRAVO_BOUNCE_* in .env)
 */
class TravoDripCommand extends Command
{
    protected $signature = 'travo:drip {--install} {--status} {--run} {--dry} {--start} {--stop} {--test=} {--stage=} {--user=} {--set=*} {--max=} {--bounces}';

    protected $description = 'Travo lifecycle email calendar';

    public function handle(): int
    {
        if ($this->option('install')) {
            TravoDrip::install();
            $s = TravoDrip::settings();
            if (!file_exists(TravoDrip::settingsPath())) {
                // staff companies: anyone in the companies that run Travo
                $s['exclude_companies'] = DB::table('companies')->whereIn('name', ['Wigmore Trading', 'Travo Services Limited', 'Travo Services Ltd', 'Travo'])->pluck('uuid')->all();
                TravoDrip::saveSettings($s);
            }
            $this->info('installed; settings: ' . TravoDrip::settingsPath());
            $this->line(json_encode(TravoDrip::settings(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        foreach ((array) $this->option('set') as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, null);
            $s = TravoDrip::settings();
            if (!array_key_exists($k, TravoDrip::DEFAULTS)) {
                $this->error("unknown setting $k");

                return 1;
            }
            $s[$k] = is_array(TravoDrip::DEFAULTS[$k]) ? array_values(array_filter(array_map('trim', explode(',', (string) $v)))) : (is_numeric($v) ? (int) $v : ($v === 'true' ? true : ($v === 'false' ? false : $v)));
            TravoDrip::saveSettings($s);
            $this->info("$k = " . json_encode($s[$k]));
        }

        if ($this->option('start')) {
            $s = TravoDrip::settings();
            $s['started_at'] = $s['started_at'] ?: now()->toIso8601String();
            $s['enabled']    = true;
            TravoDrip::saveSettings($s);
            $this->info('enrolled: ' . json_encode(TravoDrip::enrol()));
            $this->info('sending ON from ' . $s['started_at']);
        }
        if ($this->option('stop')) {
            $s            = TravoDrip::settings();
            $s['enabled'] = false;
            TravoDrip::saveSettings($s);
            $this->info('sending OFF');
        }

        if ($to = $this->option('test')) {
            $user = $this->option('user')
                ? DB::table('users')->where('public_id', $this->option('user'))->orWhere('email', $this->option('user'))->first()
                : DB::table('users')->where('email', $to)->first();
            if (!$user) {
                $this->error('no user to personalise with; pass --user=<email or public_id>');

                return 1;
            }
            $steps = $this->option('stage')
                ? array_keys(array_filter(TravoDrip::STEPS, fn ($v, $k) => $v[0] === $this->option('stage') && ($v[0] !== 'monthly' || in_array($k, [4, 14], true)), ARRAY_FILTER_USE_BOTH))
                : [0, 1, 2, 3, 4, 14];
            foreach ($steps as $step) {
                $row = (object) ['step' => $step, 'cycle' => 1, 'user_uuid' => $user->uuid, 'email' => $to, 'base_at' => now(), 'sends' => 0, 'fails' => 0, 'id' => 0];
                $ok  = TravoDrip::send($row, $user, $to);
                $this->line(($ok ? 'sent ' : 'FAILED ') . TravoDrip::STEPS[$step][0] . ' (step ' . $step . ') to ' . $to);
            }
        }

        if ($this->option('bounces')) {
            $this->line(json_encode(\App\Support\TravoBounce::check(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        if ($this->option('dry')) {
            $this->line(json_encode(TravoDrip::run(true, $this->option('max') !== null ? (int) $this->option('max') : null), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        if ($this->option('run')) {
            $r = TravoDrip::run(false, $this->option('max') !== null ? (int) $this->option('max') : null);
            unset($r['would_send']);
            $this->line(now()->toDateTimeString() . ' ' . json_encode($r));
        }

        if ($this->option('status')) {
            $this->line('settings: ' . json_encode(array_intersect_key(TravoDrip::settings(), array_flip(['enabled', 'started_at', 'daily_backlog', 'daily_cap']))));
            $this->line('eligible users: ' . TravoDrip::eligibleUsers()->count());
            foreach (DB::table(TravoDrip::TABLE)->select('status', DB::raw('count(*) n'))->groupBy('status')->get() as $r) {
                $this->line(str_pad($r->status, 14) . $r->n);
            }
            foreach (DB::table(TravoDrip::TABLE)->where('status', 'active')->select('step', DB::raw('count(*) n'))->groupBy('step')->orderBy('step')->get() as $r) {
                $this->line('  next ' . str_pad(TravoDrip::STEPS[$r->step][0] . '#' . $r->step, 14) . $r->n);
            }
            $this->line('sent today: ' . DB::table(TravoDrip::LOG)->where('sent_at', '>=', now(TravoDrip::TZ)->startOfDay()->timezone(config('app.timezone')))->where('ok', 1)->count()
                . ', failed today: ' . DB::table(TravoDrip::LOG)->where('sent_at', '>=', now(TravoDrip::TZ)->startOfDay()->timezone(config('app.timezone')))->where('ok', 0)->count());
        }

        return 0;
    }
}
