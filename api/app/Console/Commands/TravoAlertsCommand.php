<?php

namespace App\Console\Commands;

use App\Support\TravoAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 *   php artisan travo:alerts --install            create the key table, set the launch time (no back-fill before it)
 *   php artisan travo:alerts --run                write new alerts (host cron, every minute)
 *   php artisan travo:alerts --dry                what --run would look at
 *   php artisan travo:alerts --status
 *   php artisan travo:alerts --welcome=<email>    (re)send the welcome alert to one account (testing / staff)
 *   php artisan travo:alerts --set=enabled=false  pause
 */
class TravoAlertsCommand extends Command
{
    protected $signature = 'travo:alerts {--install} {--run} {--dry} {--status} {--welcome=} {--set=*}';

    protected $description = 'Travo alerts in the console Alerts box (welcome, orders, wallet)';

    public function handle(): int
    {
        if ($this->option('install')) {
            TravoAlerts::install();
            $this->info('installed: ' . json_encode(TravoAlerts::settings()));
        }
        foreach ((array) $this->option('set') as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            TravoAlerts::save([$k => $v === 'true' ? true : ($v === 'false' ? false : $v)]);
            $this->info("$k = $v");
        }
        if ($email = $this->option('welcome')) {
            $u = DB::table('users')->where('email', $email)->whereNull('deleted_at')->first();
            if (!$u) {
                $this->error('no such user');

                return 1;
            }
            DB::table(TravoAlerts::KEYS)->where('key', 'welcome:' . $u->uuid)->delete();
            $this->info(TravoAlerts::welcome($u) ? 'welcome alert written' : 'not written');
        }
        if ($this->option('dry')) {
            $this->line(json_encode(TravoAlerts::run(true)));
        }
        if ($this->option('run')) {
            $this->line(json_encode(TravoAlerts::run()));
        }
        if ($this->option('status')) {
            $this->line(json_encode(TravoAlerts::settings()));
            $this->line('alerts written: ' . DB::table(TravoAlerts::KEYS)->count() . ', unread in Alerts boxes: ' . DB::table('notifications')->where('type', 'App\\Notifications\\TravoAlert')->whereNull('read_at')->count());
        }

        return 0;
    }
}
