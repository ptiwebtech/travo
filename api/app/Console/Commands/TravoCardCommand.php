<?php

namespace App\Console\Commands;

use App\Support\TravoCard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 *   php artisan travo:card --install
 *   php artisan travo:card --set=merchant_code=MX123 --set=pay_item_id=Default_Payable_MX123 --set=enabled=true
 *   php artisan travo:card --run          re-check unfinished payments (host cron, every 5 min)
 *   php artisan travo:card --check=TRVCxxxx
 *   php artisan travo:card --status
 */
class TravoCardCommand extends Command
{
    protected $signature = 'travo:card {--install} {--run} {--status} {--check=} {--set=*}';

    protected $description = 'Travo card payments (Quickteller / Interswitch web checkout)';

    public function handle(): int
    {
        if ($this->option('install')) {
            TravoCard::install();
            $this->info('installed');
        }
        foreach ((array) $this->option('set') as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            if (!array_key_exists($k, TravoCard::DEFAULTS)) {
                $this->error("unknown setting $k");

                return 1;
            }
            $val = $v === 'true' ? true : ($v === 'false' ? false : (is_numeric($v) && !in_array($k, ['merchant_code', 'pay_item_id'], true) ? $v + 0 : $v));
            TravoCard::saveSettings([$k => $val]);
            $this->info("$k = " . json_encode($val));
        }
        if ($t = $this->option('check')) {
            $p = TravoCard::confirm($t);
            $this->line(json_encode(['status' => $p->status ?? null, 'code' => $p->resp_code ?? null, 'desc' => $p->resp_desc ?? null]));
        }
        if ($this->option('run')) {
            $this->line(now()->toDateTimeString() . ' ' . json_encode(TravoCard::recheck()));
        }
        if ($this->option('status')) {
            $s = TravoCard::settings();
            $this->line(json_encode(['ready' => TravoCard::ready(), 'mode' => $s['mode'], 'merchant_code' => $s['merchant_code'], 'pay_item_id' => $s['pay_item_id'],
                'payments' => DB::table(TravoCard::TABLE)->select('status', DB::raw('count(*) n'))->groupBy('status')->pluck('n', 'status')]));
        }

        return 0;
    }
}
