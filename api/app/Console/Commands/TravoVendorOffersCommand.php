<?php

namespace App\Console\Commands;

use App\Support\TravoVendorOffers;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Travo vendor job offers. Examples:
 *   php artisan travo:vendor-offers --install                 tables + settings (switched OFF, sends nothing)
 *   php artisan travo:vendor-offers --status
 *   php artisan travo:vendor-offers --preview=ORDER_xxx       which vendors an order would go to, and the price (sends nothing)
 *   php artisan travo:vendor-offers --test-order=ORDER_xxx --to=martin@nwike.com   one real offer (first vendor on the service) emailed to --to
 *   php artisan travo:vendor-offers --dry                     what the next run would do
 *   php artisan travo:vendor-offers --run                     the 10-minute job (host cron)
 *   php artisan travo:vendor-offers --set=enabled=true
 *   php artisan travo:vendor-offers --restore=<vendor public_id>   put an opted-out vendor back on their services
 */
class TravoVendorOffersCommand extends Command
{
    protected $signature = 'travo:vendor-offers {--install} {--status} {--run} {--dry} {--preview=} {--test-order=} {--to=} {--set=*} {--restore=}';

    protected $description = 'Travo job offers to the vendors on a service';

    public function handle(): int
    {
        if ($this->option('install')) {
            TravoVendorOffers::install();
            $this->info('installed; settings: ' . TravoVendorOffers::settingsPath());
        }

        foreach ((array) $this->option('set') as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, null);
            if (!array_key_exists($k, TravoVendorOffers::DEFAULTS)) {
                $this->error("unknown setting $k");

                return 1;
            }
            $val = is_numeric($v) ? $v + 0 : ($v === 'true' ? true : ($v === 'false' ? false : ($v === 'null' || $v === '' ? null : $v)));
            TravoVendorOffers::saveSettings([$k => $val]);
            $this->info("$k = " . json_encode($val));
        }

        if ($id = $this->option('preview') ?: $this->option('test-order')) {
            $order = Order::where('public_id', $id)->first();
            if (!$order) {
                $this->error('order not found');

                return 1;
            }
            $order->load(['payload.pickup', 'payload.dropoff', 'payload.entities', 'purchaseRate.serviceQuote.items']);
            $d       = TravoVendorOffers::jobDetails($order);
            $rate    = optional(optional($order->purchaseRate)->serviceQuote)->service_rate_uuid;
            $vendors = TravoVendorOffers::vendorsFor($rate);
            $withMail = DB::table('vendors')->whereIn('uuid', $vendors)->whereNotNull('email')->where('email', 'like', '%@%')->count();
            $this->line(json_encode([
                'job' => array_diff_key($d, ['amount' => 1]),
                'customer_price' => TravoVendorOffers::money($d['amount']),
                'vendor_price'   => $d['amount'] ? TravoVendorOffers::money(TravoVendorOffers::offerFor((int) $d['amount'])) : null,
                'vendors_on_service' => count($vendors), 'with_email' => $withMail,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if ($this->option('test-order')) {
                $to = $this->option('to');
                if (!$to || !$vendors) {
                    $this->error('need --to=<email> and at least one vendor on the service');

                    return 1;
                }
                $s     = TravoVendorOffers::settings();
                $jobId = DB::table(TravoVendorOffers::JOBS)->where('order_uuid', $order->uuid)->value('id');
                if (!$jobId) {
                    $jobId = DB::table(TravoVendorOffers::JOBS)->insertGetId([
                        'order_uuid' => $order->uuid, 'order_public_id' => $order->public_id, 'service_rate_uuid' => $rate,
                        'customer_amount' => $d['amount'], 'offer_amount' => TravoVendorOffers::offerFor((int) $d['amount']),
                        'currency' => $d['currency'], 'status' => 'open', 'note' => 'test', 'vendors' => 1,
                        'expires_at' => now()->addHours((int) $s['expiry_hours']), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $vendor = DB::table('vendors')->where('uuid', $vendors[0])->first();
                $offer  = DB::table(TravoVendorOffers::OFFERS)->where('job_id', $jobId)->where('vendor_uuid', $vendor->uuid)->first();
                if (!$offer) {
                    DB::table(TravoVendorOffers::OFFERS)->insert(['job_id' => $jobId, 'order_uuid' => $order->uuid, 'vendor_uuid' => $vendor->uuid,
                        'token' => Str::random(32), 'status' => 'sent', 'error' => 'test to ' . $to, 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    $offer = DB::table(TravoVendorOffers::OFFERS)->where('job_id', $jobId)->where('vendor_uuid', $vendor->uuid)->first();
                }
                $cache = [];
                $r     = TravoVendorOffers::sendEmail($offer, $vendor, $cache, $to);
                $this->line(($r === true ? 'sent' : 'FAILED ' . $r) . ' test offer (as vendor "' . $vendor->name . '") to ' . $to);
            }
        }

        if ($pid = $this->option('restore')) {
            $v = DB::table('vendors')->where('public_id', $pid)->orWhere('uuid', $pid)->first();
            if (!$v) {
                $this->error('vendor not found');

                return 1;
            }
            $this->info('restored ' . TravoVendorOffers::restore($v->uuid) . ' service links for ' . $v->name);
        }

        if ($this->option('dry')) {
            $s = TravoVendorOffers::settings();
            $out = $s['enabled'] ? TravoVendorOffers::run(true) : ['enabled' => false, 'queued' => TravoVendorOffers::queueNew(true)];
            $this->line(json_encode($out, JSON_PRETTY_PRINT));
        }
        if ($this->option('run')) {
            $this->line(now()->toDateTimeString() . ' ' . json_encode(TravoVendorOffers::run(false)));
        }
        if ($this->option('status')) {
            $this->line(json_encode(TravoVendorOffers::status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return 0;
    }
}
