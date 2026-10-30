<?php

namespace App\Support;

use App\Http\Controllers\OrderFlowController;
use App\Http\Controllers\QuoteShareController;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\ServiceRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Travo vendor job offers (27 Oct 2026).
 *
 * When a customer books (an order with a price is created), every vendor linked to that service
 * (the developer's service_rate_vendors list, ServiceRate::getAllVendorUuids()) is emailed a job offer:
 * service, items, pickup and drop-off AREAS (no customer name, phone, email or street address),
 * distance, date and the vendor's price = customer price minus 20%.
 * The vendor opens the link and presses "I can do this job" (a POST, so email link scanners can't accept for them).
 * Admins see who accepted, in order, on the order page, with the vendor's contact details.
 *
 * Rules agreed with Martin:
 *  - all vendors on the service, queued (about 150 emails an hour, sharing the 500/hour IONOS mailbox);
 *  - offers close after 48 hours, or earlier when the order is cancelled, dispatched or completed;
 *  - vendors have no service areas yet, so everyone on the service gets it;
 *  - every email has a "stop sending me job offers" link; using it takes the vendor off every service's
 *    vendor list (each removed link is logged in travo_vendor_offer_removed so it can be restored).
 *
 * Tables: travo_vendor_offer_jobs (one per order), travo_vendor_offers (one per order + vendor),
 * travo_vendor_offer_removed. Settings: storage/app/travo-vendor-offers.json.
 */
class TravoVendorOffers
{
    public const JOBS = 'travo_vendor_offer_jobs';

    public const OFFERS = 'travo_vendor_offers';

    public const REMOVED = 'travo_vendor_offer_removed';

    public const PAGE_BASE = 'https://travo.ng/vendor-offer/';

    public const BUS = 'travo-vendor-offers-bus';

    public const DEFAULTS = [
        'enabled'        => false,   // nothing is queued or sent until this is switched on
        'launch_at'      => null,    // only orders created after this get offers (set by --install)
        'vendor_pct'     => 80,      // vendor price = customer price x 80% (20% less)
        'round_to'       => 10000,   // round the vendor price DOWN to the nearest ₦100 (kobo)
        'expiry_hours'   => 48,
        'per_run'        => 25,      // emails per 10-minute run = 150/hour
        'max_vendors'    => 150,     // per order (safety cap)
        'skip_types'     => ['travel-book-a-bustrain-ticket'],   // bus/train tickets are sold for the operator itself
        'staff_email'    => 'hello@travoservices.com',
        'notify_staff'   => true,    // email staff when a vendor accepts
        'test_to'        => null,    // when set, every offer email goes to this address instead (testing)
        'quiet_after'    => 10,      // a vendor who hasn't answered (accept / not this time) their last 10 offers...
        'quiet_every'    => 20,      // ...then only gets 1 offer in every 20 until they answer one
    ];

    // ---- settings / install -------------------------------------------------------------------

    public static function settingsPath(): string
    {
        return storage_path('app/travo-vendor-offers.json');
    }

    public static function settings(): array
    {
        $s = is_file(self::settingsPath()) ? json_decode((string) file_get_contents(self::settingsPath()), true) : [];

        return array_merge(self::DEFAULTS, is_array($s) ? $s : []);
    }

    public static function saveSettings(array $s): void
    {
        file_put_contents(self::settingsPath(), json_encode(array_merge(self::settings(), $s), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function install(): void
    {
        if (!Schema::hasTable(self::JOBS)) {
            Schema::create(self::JOBS, function ($t) {
                $t->id();
                $t->string('order_uuid', 64)->unique();
                $t->string('order_public_id', 64)->nullable()->index();
                $t->string('service_rate_uuid', 64)->nullable();
                $t->bigInteger('customer_amount')->nullable();   // kobo
                $t->bigInteger('offer_amount')->nullable();      // kobo
                $t->string('currency', 8)->default('NGN');
                $t->string('status', 20)->default('open');        // open, closed, skipped
                $t->string('note', 190)->nullable();
                $t->unsignedInteger('vendors')->default(0);
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable(self::OFFERS)) {
            Schema::create(self::OFFERS, function ($t) {
                $t->id();
                $t->unsignedBigInteger('job_id')->index();
                $t->string('order_uuid', 64)->index();
                $t->string('vendor_uuid', 64)->index();
                $t->string('token', 40)->unique();
                $t->string('status', 20)->default('queued');   // queued, sent, accepted, declined, expired, skipped, failed
                $t->string('error', 190)->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('opened_at')->nullable();
                $t->timestamp('accepted_at')->nullable();
                $t->timestamp('declined_at')->nullable();
                $t->timestamps();
                $t->unique(['order_uuid', 'vendor_uuid']);
            });
        }
        if (!Schema::hasTable(self::REMOVED)) {
            Schema::create(self::REMOVED, function ($t) {
                $t->id();
                $t->string('vendor_uuid', 64)->index();
                $t->string('service_rate_uuid', 64);
                $t->string('source', 30)->default('vendor_opt_out');
                $t->timestamp('restored_at')->nullable();
                $t->timestamps();
            });
        }
        $s = self::settings();
        if (!$s['launch_at']) {
            self::saveSettings(['launch_at' => now()->toIso8601String()]);
        }
    }

    // ---- helpers ------------------------------------------------------------------------------

    public static function url(string $token): string
    {
        return self::PAGE_BASE . $token;
    }

    public static function money(?int $kobo, string $currency = 'NGN'): string
    {
        $sym = ['NGN' => '₦', 'GHS' => 'GH₵', 'USD' => '$', 'GBP' => '£', 'EUR' => '€'][strtoupper($currency)] ?? (strtoupper($currency) . ' ');
        $v   = ((float) $kobo) / 100;

        return $sym . number_format($v, fmod($v, 1.0) == 0.0 ? 0 : 2);
    }

    /** Vendor price for a customer price (kobo): x vendor_pct %, rounded down to round_to. */
    public static function offerFor(int $customer): int
    {
        $s   = self::settings();
        $raw = (int) floor($customer * ((float) $s['vendor_pct']) / 100);
        $r   = max(1, (int) $s['round_to']);

        return $raw >= $r ? intdiv($raw, $r) * $r : $raw;
    }

    private static function load(string $orderUuid): ?Order
    {
        return Order::with(['payload.pickup', 'payload.dropoff', 'payload.entities', 'purchaseRate.serviceQuote.items'])
            ->where('uuid', $orderUuid)->first();
    }

    private static function serviceRateUuid(Order $order): ?string
    {
        $q = optional($order->purchaseRate)->serviceQuote;
        if (!$q && $order->service_quote_uuid) {
            $q = DB::table('service_quotes')->where('uuid', $order->service_quote_uuid)->first();
        }

        return $q->service_rate_uuid ?? null;
    }

    /** Vendor uuids linked to a service (the developer's list), minus deleted vendors. */
    public static function vendorsFor(?string $rateUuid): array
    {
        if (!$rateUuid) {
            return [];
        }
        $rate = ServiceRate::where('uuid', $rateUuid)->first();
        if (!$rate) {
            return [];
        }
        $uuids = method_exists($rate, 'getAllVendorUuids') ? (array) $rate->getAllVendorUuids() : array_filter([$rate->vendor_uuid ?? null]);
        $uuids = array_values(array_unique(array_filter(array_map('strval', $uuids))));
        if (!$uuids) {
            return [];
        }

        return DB::table('vendors')->whereIn('uuid', $uuids)->whereNull('deleted_at')->pluck('uuid')->all();
    }

    /** What the vendor is told about the job: areas only, never the customer's name, contact or street address. */
    public static function jobDetails(Order $order): array
    {
        $q = app(QuoteShareController::class)->buildQuote($order, QuoteShareController::code($order->public_id));

        return [
            'ref'        => $order->public_id,
            'orderType'  => $q['orderType'] ?? 'Delivery',
            'service'    => $q['service'] ?? null,
            'items'      => $q['items'] ?? [],
            'pickup'     => $q['pickup']['label'] ?? 'Shared when you are assigned',
            'dropoff'    => $q['dropoff']['label'] ?? null,
            'distanceKm' => $q['distanceKm'] ?? null,
            'minutes'    => $q['minutes'] ?? null,
            'when'       => $q['scheduled'] ?? null,
            'created'    => $q['created'] ?? null,
            'amount'     => $q['amount'] ?? null,
            'currency'   => $q['currency'] ?? 'NGN',
            'closed'     => (bool) ($q['closed'] ?? false),
        ];
    }

    // ---- 1. queue new orders ------------------------------------------------------------------

    public static function queueNew(bool $dry = false): array
    {
        $s   = self::settings();
        $out = ['orders' => 0, 'offers' => 0, 'skipped' => 0];
        if (!$s['launch_at']) {
            return $out;
        }
        $orders = Order::whereNull('deleted_at')
            ->where('created_at', '>=', Carbon::parse($s['launch_at']))
            ->whereNotIn('status', ['canceled', 'cancelled', 'completed', 'dispatched', 'expired'])
            ->whereNotIn('uuid', DB::table(self::JOBS)->select('order_uuid'))
            ->orderBy('created_at')->limit(20)->get();

        foreach ($orders as $order) {
            $out['orders']++;
            $skip = null;
            if (in_array((string) $order->type, (array) $s['skip_types'], true)) {
                $skip = 'order type ' . $order->type;
            }
            $order->loadMissing(['payload.pickup', 'payload.dropoff', 'payload.entities', 'purchaseRate.serviceQuote.items']);
            $rate    = self::serviceRateUuid($order);
            $details = $skip ? null : rescue(fn () => self::jobDetails($order), null, true);
            $amount  = $details['amount'] ?? null;
            if (!$skip && (!$amount || $amount <= 0)) {
                $skip = 'no price';
            }
            $vendors = $skip ? [] : self::vendorsFor($rate);
            if (!$skip && !$vendors) {
                $skip = 'no vendors on this service';
            }
            $vendors = array_slice($vendors, 0, (int) $s['max_vendors']);
            if ($dry) {
                $skip ? $out['skipped']++ : ($out['offers'] += count($vendors));
                continue;
            }
            DB::transaction(function () use ($order, $rate, $amount, $details, $vendors, $skip, $s, &$out) {
                $jobId = DB::table(self::JOBS)->insertGetId([
                    'order_uuid'        => $order->uuid,
                    'order_public_id'   => $order->public_id,
                    'service_rate_uuid' => $rate,
                    'customer_amount'   => $amount,
                    'offer_amount'      => $amount ? self::offerFor((int) $amount) : null,
                    'currency'          => $details['currency'] ?? 'NGN',
                    'status'            => $skip ? 'skipped' : 'open',
                    'note'              => $skip,
                    'vendors'           => count($vendors),
                    'expires_at'        => now()->addHours((int) $s['expiry_hours']),
                    'created_at'        => now(), 'updated_at' => now(),
                ]);
                if ($skip) {
                    $out['skipped']++;

                    return;
                }
                $rows = [];
                foreach ($vendors as $v) {
                    $rows[] = ['job_id' => $jobId, 'order_uuid' => $order->uuid, 'vendor_uuid' => $v, 'token' => Str::random(32),
                        'status' => 'queued', 'created_at' => now(), 'updated_at' => now()];
                }
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table(self::OFFERS)->insertOrIgnore($chunk);
                }
                $out['offers'] += count($rows);
            });
        }

        return $out;
    }

    // ---- 2. close finished / old jobs ---------------------------------------------------------

    public static function closeJobs(): int
    {
        $n = 0;
        foreach (DB::table(self::JOBS)->where('status', 'open')->get() as $job) {
            $status = DB::table('orders')->where('uuid', $job->order_uuid)->value('status');
            $why    = null;
            if ($status === null || in_array($status, ['canceled', 'cancelled', 'completed', 'dispatched', 'expired'], true)) {
                $why = 'order ' . ($status ?: 'deleted');
            } elseif ($job->expires_at && Carbon::parse($job->expires_at)->isPast()) {
                $why = 'expired';
            }
            if (!$why) {
                continue;
            }
            DB::table(self::JOBS)->where('id', $job->id)->update(['status' => 'closed', 'note' => $why, 'closed_at' => now(), 'updated_at' => now()]);
            DB::table(self::OFFERS)->where('job_id', $job->id)->whereIn('status', ['queued', 'sent'])->update(['status' => 'expired', 'updated_at' => now()]);
            $n++;
        }

        return $n;
    }

    public static function jobOpen(object $job): bool
    {
        if ($job->status !== 'open') {
            return false;
        }
        if ($job->expires_at && Carbon::parse($job->expires_at)->isPast()) {
            return false;
        }
        $status = DB::table('orders')->where('uuid', $job->order_uuid)->value('status');

        return $status !== null && !in_array($status, ['canceled', 'cancelled', 'completed', 'dispatched', 'expired'], true);
    }

    // ---- 3. send queued offers ----------------------------------------------------------------

    public static function sendQueued(bool $dry = false): array
    {
        $s      = self::settings();
        $out    = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        $budget = (int) $s['per_run'];
        // oldest open order first, so each job reaches all its vendors before the next one starts
        $rows = DB::table(self::OFFERS . ' as o')->join(self::JOBS . ' as j', 'j.id', '=', 'o.job_id')
            ->where('o.status', 'queued')->where('j.status', 'open')
            ->orderBy('j.id')->orderBy('o.id')->limit($budget * 3)->select('o.*')->get();
        $sent = 0;
        $cache = [];
        foreach ($rows as $offer) {
            if ($budget <= 0) {
                break;
            }
            $vendor = DB::table('vendors')->where('uuid', $offer->vendor_uuid)->whereNull('deleted_at')->first();
            $email  = $vendor ? Str::lower(trim((string) $vendor->email)) : '';
            $why    = null;
            if (!$vendor) {
                $why = 'vendor deleted';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $why = 'no email';
            } elseif (self::optedOut($vendor->uuid) || TravoNetwork::suppressed($email)) {
                $why = 'opted out';
            } elseif (!rescue(fn () => TravoDrip::domainAcceptsMail($email), true, false)) {
                $why = 'email domain does not accept mail';
            }
            if (!$why && !self::shouldSend($vendor->uuid, (int) $offer->id)) {
                if (!$dry) {
                    DB::table(self::OFFERS)->where('id', $offer->id)->update(['status' => 'throttled', 'error' => 'no answer to recent offers', 'updated_at' => now()]);
                }
                $out['throttled'] = ($out['throttled'] ?? 0) + 1;
                continue;
            }
            if ($why) {
                if (!$dry) {
                    DB::table(self::OFFERS)->where('id', $offer->id)->update(['status' => 'skipped', 'error' => $why, 'updated_at' => now()]);
                }
                $out['skipped']++;
                continue;
            }
            $budget--;
            if ($dry) {
                $out['sent']++;
                continue;
            }
            $ok = self::sendEmail($offer, $vendor, $cache);
            if ((++$sent) % 10 === 0) {
                TravoDrip::freshSmtp();
            }
            if ($ok === true) {
                DB::table(self::OFFERS)->where('id', $offer->id)->update(['status' => 'sent', 'sent_at' => now(), 'error' => null, 'updated_at' => now()]);
                $out['sent']++;
            } elseif (preg_match('/\b(421|450|451|452)\b|limit exceeded|too many/i', (string) $ok)) {
                TravoDrip::freshSmtp();   // mailbox busy: leave it queued for the next run
                DB::table(self::OFFERS)->where('id', $offer->id)->update(['error' => Str::limit((string) $ok, 180), 'updated_at' => now()]);
                break;
            } else {
                DB::table(self::OFFERS)->where('id', $offer->id)->update(['status' => 'failed', 'error' => Str::limit((string) $ok, 180), 'updated_at' => now()]);
                $out['failed']++;
            }
        }

        return $out;
    }

    /**
     * Quiet vendors: once a vendor has left quiet_after offers in a row unanswered, they only get one offer in every
     * quiet_every (the 30th, 50th, ... with the defaults). Accepting or declining any offer starts the count again.
     */
    public static function shouldSend(string $vendorUuid, int $offerId): bool
    {
        $s     = self::settings();
        $after = (int) $s['quiet_after'];
        $every = max(1, (int) $s['quiet_every']);
        if ($after <= 0) {
            return true;
        }
        $lastReply = (int) DB::table(self::OFFERS)->where('vendor_uuid', $vendorUuid)->whereIn('status', ['accepted', 'declined'])->max('id');
        $n = DB::table(self::OFFERS)->where('vendor_uuid', $vendorUuid)->where('id', '>', $lastReply)->where('id', '<', $offerId)
            ->where(function ($q) { $q->whereNotNull('sent_at')->orWhere('status', 'throttled'); })->count();

        return $n < $after || ($n - $after) % $every === $every - 1;
    }

    public static function emailData(object $offer, object $vendor, array &$cache = []): ?array
    {
        $job = DB::table(self::JOBS)->where('id', $offer->job_id)->first();
        if (!$job) {
            return null;
        }
        if (!isset($cache[$job->id])) {
            $order = self::load($job->order_uuid);
            $cache[$job->id] = $order ? self::jobDetails($order) : null;
        }
        $d = $cache[$job->id];
        if (!$d) {
            return null;
        }
        $hours = max(1, (int) ceil(now()->diffInMinutes(Carbon::parse($job->expires_at), false) / 60));

        return $d + [
            'vendorName' => trim((string) $vendor->name) ?: 'there',
            'offer'      => self::money((int) $job->offer_amount, $job->currency),
            'url'        => self::url($offer->token),
            'stopUrl'    => self::url($offer->token) . '/stop',
            'hoursLeft'  => $hours,
            'vendors'    => (int) $job->vendors,
            'address'    => TravoDrip::settings()['address'] ?? 'Travo Services Limited, Lagos, Nigeria',
            'whatsapp'   => OrderFlowController::WHATSAPP,
            'subject'    => 'New job: ' . self::money((int) $job->offer_amount, $job->currency) . ' · ' . trim($d['pickup'] . ($d['dropoff'] ? ' → ' . $d['dropoff'] : '')),
        ];
    }

    /** Sends one offer email. Returns true or an error string. $to overrides the recipient (tests). */
    public static function sendEmail(object $offer, object $vendor, array &$cache = [], ?string $to = null)
    {
        $data = self::emailData($offer, $vendor, $cache);
        if (!$data) {
            return 'order not found';
        }
        $to = $to ?: (self::settings()['test_to'] ?: $vendor->email);
        try {
            Mail::send('emails.travo.vendor-offer', $data, function ($m) use ($data, $to, $vendor) {
                $m->to($to, $vendor->name ?: null)->subject($data['subject']);
                $m->replyTo('hello@travoservices.com', 'Travo');
                if ($rp = TravoBounce::returnPath()) {
                    $m->returnPath($rp);
                }
                $h = $m->getSymfonyMessage()->getHeaders();
                $h->addTextHeader('List-Unsubscribe', '<' . $data['stopUrl'] . '>');
                $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('Travo vendor offer email failed: ' . $e->getMessage(), ['offer' => $offer->id]);

            return $e->getMessage();
        }
    }

    // ---- the vendor's page --------------------------------------------------------------------

    public static function byToken(string $token): ?object
    {
        return DB::table(self::OFFERS)->where('token', $token)->first();
    }

    /** Everything the offer page shows. */
    public static function page(object $offer): array
    {
        $job    = DB::table(self::JOBS)->where('id', $offer->job_id)->first();
        $vendor = DB::table('vendors')->where('uuid', $offer->vendor_uuid)->first();
        $order  = $job ? self::load($job->order_uuid) : null;
        $d      = $order ? self::jobDetails($order) : [];
        if (!$offer->opened_at) {
            DB::table(self::OFFERS)->where('id', $offer->id)->update(['opened_at' => now(), 'updated_at' => now()]);
        }

        return [
            'offer'     => $offer,
            'job'       => $d,
            'open'      => $job && self::jobOpen($job),
            'price'     => $job ? self::money((int) $job->offer_amount, $job->currency) : null,
            'expires'   => $job && $job->expires_at ? Carbon::parse($job->expires_at) : null,
            'vendor'    => $vendor ? (trim((string) $vendor->name) ?: 'Vendor') : 'Vendor',
            'rank'      => $offer->accepted_at ? self::rank($offer) : null,
            'accepted'  => $job ? DB::table(self::OFFERS)->where('job_id', $job->id)->where('status', 'accepted')->count() : 0,
            'optedOut'  => $vendor ? self::optedOut($vendor->uuid) : false,
            'whatsapp'  => OrderFlowController::WHATSAPP,
        ];
    }

    public static function rank(object $offer): int
    {
        return 1 + DB::table(self::OFFERS)->where('job_id', $offer->job_id)->where('status', 'accepted')
            ->where(function ($q) use ($offer) {
                $q->where('accepted_at', '<', $offer->accepted_at)
                  ->orWhere(function ($q) use ($offer) { $q->where('accepted_at', $offer->accepted_at)->where('id', '<', $offer->id); });
            })->count();
    }

    /** Vendor says yes / no. Returns [ok, message]. */
    public static function respond(object $offer, bool $accept): array
    {
        $job = DB::table(self::JOBS)->where('id', $offer->job_id)->first();
        if ($offer->status === 'accepted') {
            return [true, 'already'];
        }
        if (!$job || !self::jobOpen($job)) {
            return [false, 'closed'];
        }
        DB::table(self::OFFERS)->where('id', $offer->id)->update($accept
            ? ['status' => 'accepted', 'accepted_at' => now(), 'declined_at' => null, 'updated_at' => now()]
            : ['status' => 'declined', 'declined_at' => now(), 'updated_at' => now()]);
        if ($accept) {
            $fresh  = self::byToken($offer->token);
            $vendor = DB::table('vendors')->where('uuid', $offer->vendor_uuid)->first();
            $s      = self::settings();
            if ($s['notify_staff'] && $s['staff_email']) {
                rescue(function () use ($s, $job, $vendor, $fresh) {
                    $lines = [
                        ($vendor->name ?? 'A vendor') . ' accepted the job offer for order ' . $job->order_public_id . ' (#' . self::rank($fresh) . ' to accept).',
                        'Vendor price: ' . self::money((int) $job->offer_amount, $job->currency) . '  ·  customer price: ' . self::money((int) $job->customer_amount, $job->currency),
                        'Phone: ' . ($vendor->phone ?? '-') . '  ·  Email: ' . ($vendor->email ?? '-'),
                        '',
                        'See everyone who accepted on the order page: https://travo.ng/fleet-ops/operations/orders/' . $job->order_public_id . ' (Vendor offers).',
                    ];
                    Mail::raw(implode("\n", $lines), function ($m) use ($s, $job) {
                        $m->to($s['staff_email'])->subject('[Travo vendor offers] Accepted: ' . $job->order_public_id);
                    });
                }, null, true);
            }
        }

        return [true, $accept ? 'accepted' : 'declined'];
    }

    // ---- stop offers: take the vendor off every service's vendor list ----------------------------

    public static function optedOut(string $vendorUuid): bool
    {
        return DB::table(self::REMOVED)->where('vendor_uuid', $vendorUuid)->where('service_rate_uuid', '*')->whereNull('restored_at')->exists();
    }

    public static function optOut(string $vendorUuid): int
    {
        return DB::transaction(function () use ($vendorUuid) {
            $links = Schema::hasTable('service_rate_vendors')
                ? DB::table('service_rate_vendors')->where('vendor_uuid', $vendorUuid)->pluck('service_rate_uuid')->all() : [];
            $now  = now();
            $rows = [['vendor_uuid' => $vendorUuid, 'service_rate_uuid' => '*', 'source' => 'vendor_opt_out', 'created_at' => $now, 'updated_at' => $now]];
            foreach ($links as $r) {
                $rows[] = ['vendor_uuid' => $vendorUuid, 'service_rate_uuid' => $r, 'source' => 'vendor_opt_out', 'created_at' => $now, 'updated_at' => $now];
            }
            if (!self::optedOut($vendorUuid)) {
                DB::table(self::REMOVED)->insert($rows);
            }
            if ($links) {
                DB::table('service_rate_vendors')->where('vendor_uuid', $vendorUuid)->delete();
            }
            // old single-vendor field on a service
            DB::table('service_rates')->where('vendor_uuid', $vendorUuid)->update(['vendor_uuid' => null]);
            DB::table(self::OFFERS)->where('vendor_uuid', $vendorUuid)->where('status', 'queued')->update(['status' => 'skipped', 'error' => 'opted out', 'updated_at' => $now]);

            return count($links);
        });
    }

    /** Admin: put a vendor back on the services they were removed from. */
    public static function restore(string $vendorUuid): int
    {
        $rows = DB::table(self::REMOVED)->where('vendor_uuid', $vendorUuid)->whereNull('restored_at')->get();
        $n    = 0;
        foreach ($rows as $r) {
            if ($r->service_rate_uuid !== '*' && !DB::table('service_rate_vendors')->where('vendor_uuid', $vendorUuid)->where('service_rate_uuid', $r->service_rate_uuid)->exists()) {
                DB::table('service_rate_vendors')->insert(['service_rate_uuid' => $r->service_rate_uuid, 'vendor_uuid' => $vendorUuid, 'created_at' => now(), 'updated_at' => now()]);
                $n++;
            }
        }
        DB::table(self::REMOVED)->where('vendor_uuid', $vendorUuid)->whereNull('restored_at')->update(['restored_at' => now(), 'updated_at' => now()]);

        return $n;
    }

    // ---- admin: offers on one order ---------------------------------------------------------

    public static function forOrder(Order $order): array
    {
        $job = DB::table(self::JOBS)->where('order_uuid', $order->uuid)->first();
        if (!$job) {
            $s = self::settings();

            return ['job' => null, 'note' => !$s['enabled'] ? 'Vendor offers are switched off.'
                : (($s['launch_at'] && $order->created_at < Carbon::parse($s['launch_at'])) ? 'This order was placed before vendor offers started.' : 'Offers will go out within 10 minutes.')];
        }
        $offers  = DB::table(self::OFFERS)->where('job_id', $job->id)->get();
        $vendors = DB::table('vendors')->whereIn('uuid', $offers->pluck('vendor_uuid'))->get(['uuid', 'public_id', 'name', 'email', 'phone'])->keyBy('uuid');
        $acc     = $offers->where('status', 'accepted')->sortBy(fn ($o) => [$o->accepted_at, $o->id])->values();
        $list    = [];
        foreach ($acc as $i => $o) {
            $v      = $vendors->get($o->vendor_uuid);
            $list[] = [
                'rank'        => $i + 1,
                'name'        => $v->name ?? 'Vendor',
                'email'       => $v->email ?? null,
                'phone'       => $v->phone ?? null,
                'public_id'   => $v->public_id ?? null,
                'accepted_at' => Carbon::parse($o->accepted_at)->toIso8601String(),
                'after'       => $o->sent_at ? Carbon::parse($o->sent_at)->diffForHumans(Carbon::parse($o->accepted_at), true) : null,
            ];
        }
        $count = $offers->groupBy('status')->map->count();

        return [
            'job' => [
                'status'   => $job->status,
                'note'     => $job->note,
                'offer'    => self::money((int) $job->offer_amount, $job->currency),
                'customer' => self::money((int) $job->customer_amount, $job->currency),
                'expires'  => $job->expires_at ? Carbon::parse($job->expires_at)->toIso8601String() : null,
                'vendors'  => (int) $job->vendors,
            ],
            'counts'   => $count,
            'emailed'  => $offers->whereNotNull('sent_at')->count(),
            'accepted' => $list,
        ];
    }

    // ---- the 10-minute job --------------------------------------------------------------------

    public static function run(bool $dry = false): array
    {
        $s = self::settings();
        if (!$s['enabled']) {
            return ['enabled' => false];
        }
        $out = ['closed' => $dry ? 0 : self::closeJobs()];
        $out['queued'] = self::queueNew($dry);
        $out['emails'] = self::sendQueued($dry);

        return $out;
    }

    public static function status(): array
    {
        $s = self::settings();

        return [
            'enabled'   => $s['enabled'],
            'launch_at' => $s['launch_at'],
            'test_to'   => $s['test_to'] ? 'set' : null,
            'jobs'      => DB::table(self::JOBS)->select('status', DB::raw('count(*) n'))->groupBy('status')->pluck('n', 'status'),
            'offers'    => DB::table(self::OFFERS)->select('status', DB::raw('count(*) n'))->groupBy('status')->pluck('n', 'status'),
            'opted_out' => DB::table(self::REMOVED)->where('service_rate_uuid', '*')->whereNull('restored_at')->count(),
        ];
    }
}
