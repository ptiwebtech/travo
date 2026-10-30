<?php

namespace App\Console\Commands;

use App\Support\TravoChat;
use Illuminate\Console\Command;

/**
 *   php artisan travo:chat --install
 *   php artisan travo:chat --run                       email staff about support messages waiting > 5 min (host cron)
 *   php artisan travo:chat --set=support_users=user_abc,user_def
 *   php artisan travo:chat --notify-dry                who would get a "new message" email now (sends nothing)
 */
class TravoChatCommand extends Command
{
    protected $signature = 'travo:chat {--install} {--run} {--notify-dry} {--status} {--set=*}';

    protected $description = 'Travo chat: support routing and reply alerts';

    public function handle(): int
    {
        if ($this->option('install')) {
            TravoChat::install();
            $this->info('installed');
        }
        foreach ((array) $this->option('set') as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            if (!array_key_exists($k, TravoChat::DEFAULTS)) {
                $this->error("unknown setting $k");

                return 1;
            }
            $val = is_array(TravoChat::DEFAULTS[$k]) ? array_values(array_filter(array_map('trim', explode(',', $v)))) : (is_numeric($v) ? $v + 0 : $v);
            TravoChat::saveSettings([$k => $val]);
            $this->info("$k = " . json_encode($val));
        }
        if ($this->option('run')) {
            $this->line(now()->toDateTimeString() . ' alerts sent: ' . TravoChat::alertWaiting());
            $r = TravoChat::notifyRecipients();
            $this->line(now()->toDateTimeString() . ' customer emails sent: ' . $r['sent'] . ', skipped: ' . $r['skipped']);
        }
        if ($this->option('notify-dry')) {
            $r = TravoChat::notifyRecipients(true);
            $this->line(count($r['would']) . ' would be emailed');
            foreach ($r['would'] as $w) {
                $this->line(' - ' . $w['from'] . ' -> ' . preg_replace('/^(.).*(@.*)$/', '$1***$2', $w['to']) . ': ' . $w['preview']);
            }
        }
        if ($this->option('status')) {
            $s = TravoChat::settings();
            $this->line(json_encode(['support_users' => $s['support_users'], 'found' => TravoChat::supportUsers()->pluck('name')], JSON_UNESCAPED_UNICODE));
        }

        return 0;
    }
}
