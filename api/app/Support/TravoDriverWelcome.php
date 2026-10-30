<?php

namespace App\Support;

use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Support\Carbon;

/**
 * Welcome series for newly approved drivers (29 Oct 2026).
 * On approval the driver gets one welcome email and five alerts in the Alerts box:
 * welcome, how you earn, what your vehicle could take home (with examples), what we expect, refer a driver.
 *
 * Earnings terms (Martin, 29 Oct): the driver keeps 75% of each fare, plus a 5% bonus (up to 80%) reviewed monthly,
 * plus 2% of a referred driver's earnings for their first 6 months (paid by Travo).
 * Example fares are typical customer prices from Travo bookings, Apr–Oct 2026 (median per service, rounded).
 */
class TravoDriverWelcome
{
    public const SHARE = 0.75;
    public const BONUS = 0.05;

    /** Bonus criteria (Martin, 29 Oct). */
    public const EXPECT = [
        'Keep your location switched on in the Travo app while you\'re online.',
        'Be available for work, and accept the jobs you can do.',
        'Meet customers promptly and on time, and keep them updated.',
        'Complete deliveries successfully, with care, and take a photo at drop-off. Aim for zero complaints.',
        'Be good and honest all round: polite to customers, honest about delays and problems.',
    ];

    /** What each vehicle usually does on Travo, with example fares (naira) from recent bookings. */
    public const EXAMPLES = [
        'bike'    => ['parcel and package deliveries', [['A local parcel delivery', 12000], ['A longer cross-town delivery', 28000]]],
        'car'     => ['airport pickups, booked drivers and small parcels', [['An airport pickup', 50000], ['A local parcel delivery', 12000]]],
        'minivan' => ['airport pickups, group trips and small moves', [['An airport pickup', 50000], ['A small van move', 36000]]],
        'van'     => ['van moves and haulage', [['A small van move', 36000], ['A typical van or truck job', 118000]]],
        'truck'   => ['haulage and heavy loads', [['A typical van or truck job', 118000], ['A long-distance haulage job (about 435 km)', 990000]]],
        'bus'     => ['airport and group pickups, and charters', [['An airport group pickup', 50000]]],
        'tanker'  => ['fuel and water deliveries', []],
    ];

    public static function naira(float $n): string
    {
        return '₦' . number_format(round($n / 100) * 100);
    }

    /** Example lines for one vehicle type. */
    public static function exampleLines(?string $vehicle): array
    {
        [$what, $jobs] = self::EXAMPLES[$vehicle] ?? self::EXAMPLES['car'];
        $out = [];
        foreach ($jobs as [$label, $fare]) {
            $out[] = $label . ': the customer pays about ' . self::naira($fare) . '. You keep ' . self::naira($fare * self::SHARE)
                . ', or ' . self::naira($fare * (self::SHARE + self::BONUS)) . ' with the 5% bonus. Ten jobs like this: '
                . self::naira($fare * self::SHARE * 10) . ' (' . self::naira($fare * (self::SHARE + self::BONUS) * 10) . ' with the bonus).';
        }
        if (!$out) {
            $out[] = ucfirst($what) . ' are priced per trip, and every job offer shows what the job pays before you accept. You keep 75% of the fare, or 80% with the 5% bonus.';
        }

        return $out;
    }

    /** Which bookings (orders.type prefixes) suit each vehicle. Bus/train tickets are partner buses, not driver jobs. */
    public const SUITS = [
        'bike'    => ['ship-a-parcel'],
        'car'     => ['ship-a-parcel', 'driver-book', 'airport-pickup', 'vehicle-charter'],
        'minivan' => ['ship-a-parcel', 'driver-book', 'airport-pickup', 'haulage', 'vehicle-charter'],
        'van'     => ['ship-a-parcel', 'haulage'],
        'truck'   => ['haulage', 'rubbish-collection'],
        'bus'     => ['airport-pickup', 'vehicle-charter'],
        'tanker'  => ['fuel-delivery'],
    ];

    /**
     * Live numbers for the driver page (29 Oct 2026): demand in the last 7 days (all + suited to their vehicle),
     * the busiest booking times (last 30 days, Lagos time), drivers online now, and their own month so far.
     */
    public static function insights(Driver $d, ?string $vehicle): array
    {
        $vehicle = isset(self::SUITS[$vehicle ?? '']) ? $vehicle : 'car';
        $global  = \Illuminate\Support\Facades\Cache::remember('travo:driver-insights:v1', 600, function () {
            $base  = fn () => \Illuminate\Support\Facades\DB::table('orders')->whereNull('deleted_at')->where('status', '!=', 'canceled');
            $week  = $base()->where('created_at', '>=', now()->subDays(7))->select('type')->get()->pluck('type')->all();
            $hours = array_fill(0, 24, 0);
            $days  = array_fill(0, 7, 0);
            foreach ($base()->where('created_at', '>=', now()->subDays(30))->where('type', 'not like', 'travel-book%')->pluck('created_at') as $c) {
                $t = Carbon::parse($c, 'UTC')->timezone('Africa/Lagos');
                $hours[(int) $t->format('G')]++;
                $days[(int) $t->format('N') - 1]++;
            }
            // busiest 3-hour windows between 6am and 10pm, best two that don't overlap
            $win = [];
            for ($h = 6; $h <= 19; $h++) {
                $win[$h] = $hours[$h] + $hours[$h + 1] + $hours[$h + 2];
            }
            arsort($win);
            $pick = [];
            foreach ($win as $h => $n) {
                if ($n <= 0 || count($pick) >= 2) {
                    break;
                }
                foreach ($pick as $p) {
                    if (abs($p - $h) < 3) {
                        continue 2;
                    }
                }
                $pick[] = $h;
            }
            sort($pick);
            $fmt   = fn ($h) => Carbon::createFromTime($h % 24)->format('ga');
            $names = ['Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays', 'Sundays'];
            arsort($days);

            return [
                'week'     => $week,
                'busy'     => array_map(fn ($h) => $fmt($h) . '–' . $fmt($h + 3), $pick),
                'busyDay'  => reset($days) > 0 ? $names[array_key_first($days)] : null,
            ];
        });
        // live (not cached): approved drivers online right now
        $global['online'] = Driver::where('status', 'active')->where('online', 1)->get()->filter(fn ($x) => TravoDriver::isOnline($x))->count();
        $suits  = self::SUITS[$vehicle];
        $suited = count(array_filter($global['week'], function ($t) use ($suits) {
            foreach ($suits as $p) {
                if (str_starts_with((string) $t, $p)) {
                    return true;
                }
            }

            return false;
        }));

        // their own month so far: completed jobs and an estimate of their 75%
        [$jobs, $earned] = \Illuminate\Support\Facades\Cache::remember('travo:driver-month:' . $d->uuid, 300, function () use ($d) {
            $done   = \Fleetbase\FleetOps\Models\Order::where('driver_assigned_uuid', $d->uuid)->whereNull('deleted_at')
                ->where('status', 'completed')->where('updated_at', '>=', now()->timezone('Africa/Lagos')->startOfMonth()->timezone('UTC'))->limit(200)->get();
            $earned = 0;
            foreach ($done as $o) {
                $q = rescue(fn () => app(\App\Http\Controllers\QuoteShareController::class)->buildQuote($o, \App\Http\Controllers\QuoteShareController::code($o->public_id)), [], false) ?: [];
                if (!empty($q['amount']) && ($q['currency'] ?? 'NGN') === 'NGN') {
                    $earned += $q['amount'] / 100 * self::SHARE;
                }
            }

            return [$done->count(), $earned];
        });

        return [
            'week'        => count($global['week']),
            'suited'      => $suited,
            'what'        => self::EXAMPLES[$vehicle][0],
            'busy'        => $global['busy'],
            'busyDay'     => $global['busyDay'],
            'onlineNow'   => $global['online'],
            'monthJobs'   => $jobs,
            'monthEarned' => (int) round($earned / 100) * 100,
        ];
    }

    public const SMALL_PRINT = 'These are examples based on recent Travo bookings, not a promise of earnings. What you earn depends on the jobs in your area and how many you accept. Your fuel and running costs come out of your share.';

    /** Send the welcome email + alerts. Safe to call twice: alerts are keyed per driver. */
    public static function send(Driver $d, object $u, ?string $vehicle, ?string $note = null): void
    {
        $first   = trim(explode(' ', trim((string) $u->name))[0] ?? '');
        $vname   = TravoDriver::VEHICLES[$vehicle ?? ''] ?? 'vehicle';
        [$what]  = self::EXAMPLES[$vehicle] ?? self::EXAMPLES['car'];
        $ex      = self::exampleLines($vehicle);
        $k       = 'driver-welcome:' . $d->uuid . ':';
        $extra   = ['kind' => 'driver', 'url' => '#drive'];

        // alerts: written oldest-first with a few seconds between them, so the welcome sits on top of the bell
        $alerts = [
            ['refer', '🤝 Refer a driver, earn 2%',
                'Know a good driver? When someone you refer is approved and starts working, you get 2% of their earnings for their first 6 months. Travo pays this, so it doesn\'t come out of their pay. Ask them to give our team your name and phone number when they apply.'],
            ['expect', '⭐ What makes a great Travo driver',
                implode(' ', self::EXPECT) . ' Do these consistently and you earn the extra 5% bonus each month.'],
            ['examples', '🧮 What you could take home with your ' . strtolower($vname),
                'With your ' . strtolower($vname) . ' you\'ll mostly get ' . $what . '. ' . implode(' ', $ex) . ' ' . self::SMALL_PRINT],
            ['earn', '💰 How you earn with Travo',
                'You keep 75% of every fare. Travo keeps 25% to find customers, take payments and support you on every job. Earn an extra 5% (up to 80% of the fare) each month by meeting our driver standards, reviewed monthly. Your earnings are paid to the bank account in your application, and we\'ll confirm your payment schedule with you.'],
            ['welcome', '🎉 Welcome to the Travo driver team' . ($first ? ', ' . $first : '') . '!',
                'You\'re approved, and we\'re really glad to have you with us. We believe in clear, honest and friendly communication: if you ever have a question or a problem, message us in Chat and we\'ll get back to you. Tap here, then Go online when you\'re ready for jobs.'],
        ];
        $base = now()->subSeconds(count($alerts));
        foreach ($alerts as $i => [$key, $subject, $message]) {
            TravoAlerts::send($u->uuid, $k . $key, $subject, $message, $extra, $base->copy()->addSeconds($i + 1));
        }

        if ($u->email ?? null) {
            $paras = array_merge(
                ['You\'re approved to drive with Travo, and we\'re really glad to have you on the team.' . ($note ? ' ' . $note : ''),
                 'We believe in clear, honest and friendly communication. If you ever have a question or a problem, message us in Chat in the Travo app and we\'ll get back to you.',
                 'GETTING STARTED: open Travo, tap Drive with Travo and switch on Go online when you\'re ready. Keep the Travo page open while you\'re online so we can see where you are. When we offer you a job you\'ll get an email and see it in the app. Tap Accept to take it: the first driver to accept gets the job. Mark it Picked up when you collect it, and Delivered (with a photo) when you drop it off.',
                 'HOW YOU EARN: you keep 75% of every fare. Travo keeps 25% to find customers, take payments and support you. Earn an extra 5% (up to 80%) each month by meeting our driver standards, reviewed monthly. Your earnings are paid to the bank account in your application, and we\'ll confirm your payment schedule with you.',
                 'WHAT YOUR ' . strtoupper($vname) . ' COULD TAKE HOME: you\'ll mostly get ' . $what . '.'],
                $ex,
                [self::SMALL_PRINT,
                 'WHAT WE EXPECT (and what earns the 5% bonus):'],
                array_map(fn ($s) => '• ' . $s, self::EXPECT),
                ['REFER A DRIVER, EARN 2%: when a driver you refer is approved and starts working, you get 2% of their earnings for their first 6 months, paid by Travo. Ask them to give our team your name and phone number when they apply.',
                 'Welcome aboard!' . ($first ? ' We\'re looking forward to working with you, ' . $first . '.' : '')]
            );
            TravoDriver::mail($u->email, 'Welcome to the Travo driver team' . ($first ? ', ' . $first : ''), 'Welcome to the Travo driver team' . ($first ? ', ' . $first : '') . '!',
                $paras, 'https://travo.ng/#drive', 'Open Drive with Travo');
        }
    }
}
