<?php

namespace App\Support;

use App\Http\Controllers\QuoteShareController;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Travo email calendar (lifecycle emails), Oct 2026.
 *
 * Every verified customer / vendor account goes through:
 *   step 0  welcome            straight after sign-up + email verification (existing users: released 300 a day)
 *   step 1  free_quote         1 day later
 *   step 2  week               7 days after the start
 *   step 3  month              30 days after the start (stats for the last 30 days)
 *   step 4+ monthly            every 30 days after that, up to month 12, then the user is let go (status "done")
 *
 * Placing an order (any order/quote the user or their company creates) pauses the chain; one month after
 * their latest order it starts again at "free_quote" (no second welcome). Unsubscribing stops everything.
 *
 * Runs from host cron every hour (php artisan travo:drip --run). Sends only 08:00-20:00 Lagos time,
 * within a daily cap. Settings: storage/app/travo-drip.json (see settings()).
 */
class TravoDrip
{
    public const TABLE = 'travo_drip';

    public const LOG = 'travo_drip_log';

    public const TZ = 'Africa/Lagos';

    /** step => [key, days after the cycle start] */
    public const STEPS = [
        0 => ['welcome', 0], 1 => ['free_quote', 1], 2 => ['week', 7], 3 => ['month', 30],
        4 => ['monthly', 60], 5 => ['monthly', 90], 6 => ['monthly', 120], 7 => ['monthly', 150], 8 => ['monthly', 180],
        9 => ['monthly', 210], 10 => ['monthly', 240], 11 => ['monthly', 270], 12 => ['monthly', 300], 13 => ['monthly', 330], 14 => ['monthly', 360],
    ];

    public const LAST_STEP = 14;

    public const UNSUB_BASE = 'https://travo.ng/unsubscribe/';

    public const DEFAULTS = [
        'enabled'        => false,      // master switch: nothing is sent until this is true
        'started_at'     => null,       // set on --start; users verified after this are new sign-ups (no queue)
        'daily_backlog'  => 300,        // existing users released into the calendar per day
        'backfill_days'  => 183,
        'send_after'     => null,       // nothing is sent before this time (ISO date), e.g. the go-live time
        'bounce_imap_host' => 'imap.ionos.co.uk',   // reads bounce reports with the site's SMTP login (TravoBounce)
        'bounce_last_uid' => 0,
        'inactive_days'  => 90,         // stop if the user hasn't logged in within this many days of the emails starting        // existing users: only those who signed up in the last ~6 months before the start
        'daily_cap'      => 900,        // total lifecycle emails per day
        'send_from_hour' => 8,          // Lagos time
        'send_to_hour'   => 20,
        'exclude_types'  => ['admin', 'driver'],
        'exclude_domains' => ['travoservices.com', 'wigmoretrading.com', 'travo.ng', 'example.com', 'example.org', 'test.com', 'mailinator.com', 'fleetbase.io'],
        'exclude_companies' => [],      // company uuids of staff companies (filled on install)
        'reply_to'       => 'hello@travoservices.com',
        'address'        => 'Travo Services Limited · Lagos, Nigeria',
        'monthly_news'   => '',         // optional "New on Travo" paragraph for the monthly emails
    ];

    // ---- settings ---------------------------------------------------------------------------

    public static function settingsPath(): string
    {
        return storage_path('app/travo-drip.json');
    }

    public static function settings(): array
    {
        $s = json_decode((string) @file_get_contents(self::settingsPath()), true);

        return array_merge(self::DEFAULTS, is_array($s) ? $s : []);
    }

    public static function saveSettings(array $s): void
    {
        file_put_contents(self::settingsPath(), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    // ---- tables -----------------------------------------------------------------------------

    public static function install(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function ($t) {
                $t->id();
                $t->char('user_uuid', 36)->unique();
                $t->string('email', 191)->index();
                $t->string('status', 20)->index();          // queued | active | done | unsubscribed | failed | excluded
                $t->unsignedTinyInteger('step')->default(0); // next step to send
                $t->unsignedSmallInteger('cycle')->default(1);
                $t->dateTime('base_at')->nullable();        // start of the current cycle (step offsets count from here)
                $t->dateTime('next_at')->nullable()->index();
                $t->dateTime('last_order_at')->nullable();  // newest order already accounted for
                $t->dateTime('last_sent_at')->nullable();
                $t->string('last_sent', 20)->nullable();
                $t->unsignedSmallInteger('sends')->default(0);
                $t->unsignedTinyInteger('fails')->default(0);
                $t->dateTime('released_at')->nullable();    // when a queued (existing) user was let in
                $t->dateTime('unsubscribed_at')->nullable();
                $t->string('note', 191)->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable(self::LOG)) {
            Schema::create(self::LOG, function ($t) {
                $t->id();
                $t->char('user_uuid', 36)->index();
                $t->string('email', 191);
                $t->string('stage', 20);
                $t->unsignedTinyInteger('step');
                $t->unsignedSmallInteger('cycle');
                $t->boolean('ok')->default(true);
                $t->string('error', 255)->nullable();
                $t->dateTime('sent_at')->index();
            });
        }
    }

    // ---- who is in ------------------------------------------------------------------------

    /** Eligible users (verified customer / vendor accounts) as a query on users. */
    public static function eligibleUsers()
    {
        $s = self::settings();
        $q = DB::table('users')
            ->whereNull('deleted_at')
            ->whereNotNull('email_verified_at')
            ->whereNotNull('email')
            ->where('email', 'like', '%@%.%')
            ->where(function ($w) use ($s) {
                $w->whereNull('type')->orWhereNotIn('type', $s['exclude_types']);
            })
            ->where(function ($w) {
                $w->whereNull('status')->orWhereNotIn('status', ['inactive', 'disabled', 'banned', 'suspended']);
            });
        // drivers have ordinary user accounts: leave out anyone linked to a driver record
        $q->whereNotIn('uuid', DB::table('drivers')->whereNotNull('user_uuid')->whereNull('deleted_at')->select('user_uuid'));
        foreach ($s['exclude_domains'] as $d) {
            $q->where('email', 'not like', '%@' . $d);
        }
        if ($s['exclude_companies']) {
            $q->where(function ($w) use ($s) {
                $w->whereNull('company_uuid')->orWhereNotIn('company_uuid', $s['exclude_companies']);
            });
        }

        return $q;
    }

    /** Adds every eligible user not yet in the calendar. Before the start date = queued (existing users). */
    public static function enrol(): array
    {
        $s       = self::settings();
        $start   = $s['started_at'] ? Carbon::parse($s['started_at']) : now();
        $now     = now();
        $added   = ['queued' => 0, 'active' => 0, 'excluded' => 0];
        // existing users who signed up more than backfill_days before the start are left out (decided 26 Oct: last 6 months only)
        $cutoff  = $start->copy()->subDays((int) $s['backfill_days']);
        // chunkById, not chunk: rows are inserted as we go, so offset paging would skip users
        self::eligibleUsers()
            ->whereNotIn('uuid', DB::table(self::TABLE)->select('user_uuid'))
            ->select(['id', 'uuid', 'email', 'company_uuid', 'email_verified_at', 'created_at'])
            ->chunkById(500, function ($users) use ($start, $now, $cutoff, &$added) {
                $rows = [];
                foreach ($users as $u) {
                    if (!filter_var(trim($u->email), FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }
                    $isNew  = Carbon::parse($u->email_verified_at)->gte($start);
                    $tooOld = !$isNew && Carbon::parse($u->created_at)->lt($cutoff);
                    $status = $isNew ? 'active' : ($tooOld ? 'excluded' : 'queued');
                    $rows[] = [
                        'user_uuid'     => $u->uuid,
                        'email'         => strtolower(trim($u->email)),
                        'status'        => $status,
                        'note'          => $tooOld ? 'signed up before the backfill window' : null,
                        'step'          => 0,
                        'cycle'         => 1,
                        'base_at'       => $isNew ? $now : null,
                        'next_at'       => $isNew ? $now : null,
                        'last_order_at' => self::lastOrderAt($u->uuid, $u->company_uuid),
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ];
                    $added[$status]++;
                }
                if ($rows) {
                    DB::table(self::TABLE)->insertOrIgnore($rows);
                }
            });

        return $added;
    }

    /** Lets in the next batch of existing users (newest sign-ups first), up to daily_backlog per Lagos day. */
    public static function release(): int
    {
        $s     = self::settings();
        $today = now(self::TZ)->startOfDay()->timezone(config('app.timezone'));
        $done  = DB::table(self::TABLE)->where('released_at', '>=', $today)->count();
        $room  = max(0, (int) $s['daily_backlog'] - $done);
        if (!$room) {
            return 0;
        }
        $ids = DB::table(self::TABLE . ' as d')
            ->join('users as u', 'u.uuid', '=', 'd.user_uuid')
            ->where('d.status', 'queued')
            ->orderByDesc('u.created_at')
            ->limit($room)
            ->pluck('d.id');
        $now = now();
        DB::table(self::TABLE)->whereIn('id', $ids)->update(['status' => 'active', 'base_at' => $now, 'next_at' => $now, 'released_at' => $now, 'updated_at' => $now]);

        return count($ids);
    }

    public static function lastOrderAt(string $userUuid, ?string $companyUuid): ?string
    {
        $q = DB::table('orders')->whereNull('deleted_at')->where(function ($w) use ($userUuid, $companyUuid) {
            $w->where('created_by_uuid', $userUuid)->orWhere('customer_uuid', $userUuid);
            if ($companyUuid) {
                $w->orWhere('company_uuid', $companyUuid);
            }
        });

        return $q->max('created_at');
    }

    // ---- sending ----------------------------------------------------------------------------

    /** One hourly run. Returns a summary. $dry: work out what would be sent without sending or changing anything. */
    public static function run(bool $dry = false, ?int $max = null): array
    {
        $sessionCount = 0;
        $s   = self::settings();
        $out = ['enrolled' => null, 'released' => 0, 'sent' => 0, 'failed' => 0, 'restarted' => 0, 'skipped' => 0, 'would_send' => []];
        if (!$dry) {
            $out['enrolled'] = self::enrol();
        }
        if (!$s['enabled'] && !$dry) {
            $out['note'] = 'disabled';

            return $out;
        }
        if (!$dry && $s['send_after'] && now()->lt(Carbon::parse($s['send_after']))) {
            $out['note'] = 'scheduled to start at ' . $s['send_after'];

            return $out;
        }
        $hour = (int) now(self::TZ)->format('G');
        if (!$dry && ($hour < $s['send_from_hour'] || $hour >= $s['send_to_hour'])) {
            $out['note'] = 'outside sending hours';

            return $out;
        }
        if (!$dry) {
            $out['bounces']  = TravoBounce::configured() ? TravoBounce::check() : 'not set up';
            $out['released'] = self::release();
        }

        // spread the day's allowance over the remaining sending hours
        $today     = now(self::TZ)->startOfDay()->timezone(config('app.timezone'));
        $sentToday = DB::table(self::LOG)->where('sent_at', '>=', $today)->count();
        $hoursLeft = max(1, $s['send_to_hour'] - max($hour, $s['send_from_hour']));
        $budget    = (int) ceil(max(0, $s['daily_cap'] - $sentToday) / $hoursLeft);
        if ($max !== null) {
            $budget = min($budget, $max);
        }

        $due = DB::table(self::TABLE)->where('status', 'active')->where('next_at', '<=', now())->orderBy('next_at')->limit(max($budget, 0) * 2 + 10)->get();
        foreach ($due as $row) {
            if ($out['sent'] + count($out['would_send']) >= $budget) {
                break;
            }
            $user = DB::table('users')->where('uuid', $row->user_uuid)->first();
            if (!$user || $user->deleted_at) {
                $dry || DB::table(self::TABLE)->where('id', $row->id)->update(['status' => 'excluded', 'note' => 'user removed', 'updated_at' => now()]);
                $out['skipped']++;
                continue;
            }
            // placed an order since we last looked? pause, and start again (at free_quote) a month after it
            if ($row->step > 0) {
                $last = self::lastOrderAt($row->user_uuid, $user->company_uuid);
                if ($last && (!$row->last_order_at || Carbon::parse($last)->gt(Carbon::parse($row->last_order_at)))) {
                    $base = Carbon::parse($last)->addDays(29);   // free_quote (+1 day) lands 30 days after the order
                    $dry || DB::table(self::TABLE)->where('id', $row->id)->update([
                        'step' => 1, 'cycle' => $row->cycle + 1, 'base_at' => $base, 'next_at' => $base->copy()->addDays(1),
                        'last_order_at' => $last, 'updated_at' => now(),
                    ]);
                    $out['restarted']++;
                    continue;
                }
            }
            // 3 months into a cycle without logging in since it started: stop (decided 26 Oct)
            if ($row->base_at && now()->gte(Carbon::parse($row->base_at)->addDays((int) $s['inactive_days']))) {
                $login = $user->last_login ? rescue(fn () => Carbon::parse($user->last_login), null, false) : null;
                if (!$login || $login->lt(Carbon::parse($row->base_at))) {
                    $dry || DB::table(self::TABLE)->where('id', $row->id)->update(['status' => 'done', 'next_at' => null, 'note' => 'no login within ' . $s['inactive_days'] . ' days of the emails starting', 'updated_at' => now()]);
                    $out['stopped_inactive'] = ($out['stopped_inactive'] ?? 0) + 1;
                    continue;
                }
            }
            // address whose domain can't receive mail (typos like gmial.com, dead domains): stop
            if (!self::domainAcceptsMail($row->email)) {
                $dry || DB::table(self::TABLE)->where('id', $row->id)->update(['status' => 'bounced', 'next_at' => null, 'note' => 'email domain does not accept mail', 'updated_at' => now()]);
                $out['bad_domain'] = ($out['bad_domain'] ?? 0) + 1;
                continue;
            }
            if ($dry) {
                $out['would_send'][] = ['email' => $row->email, 'stage' => self::STEPS[$row->step][0], 'step' => $row->step];
                continue;
            }
            $res = self::send($row, $user);
            $res ? $out['sent']++ : $out['failed']++;
            // IONOS limits how many emails go through one SMTP connection: start a fresh one every 10 (27 Oct)
            if ((++$sessionCount) % 10 === 0) {
                self::freshSmtp();
            }
        }

        return $out;
    }

    /** Sends the row's current step and moves it on. */
    public static function send(object $row, object $user, ?string $overrideTo = null): bool
    {
        [$stage] = self::STEPS[$row->step];
        $month   = $row->step >= 3 ? $row->step - 2 : null;   // step 3 = month 1, step 4 = month 2 ... step 14 = month 12
        try {
            $data    = self::emailData($stage, $user, $month, $row);
            $to      = $overrideTo ?: $row->email;
            $unsub   = self::unsubscribeUrl($user->public_id);
            Mail::send('emails.travo.lifecycle.email', $data, function ($m) use ($to, $user, $data, $unsub) {
                $m->to($to, $user->name ?: null)->subject($data['subject']);
                $reply = self::settings()['reply_to'];
                if ($reply) {
                    $m->replyTo($reply, 'Travo');
                }
                if ($rp = TravoBounce::returnPath()) {
                    $m->returnPath($rp);   // bounces go to the bounce mailbox, read by TravoBounce::check()
                }
                $h = $m->getSymfonyMessage()->getHeaders();
                $h->addTextHeader('List-Unsubscribe', '<' . $unsub . '>');
                $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
            if ($overrideTo) {
                return true;   // test send: no state change
            }
            $next = $row->step + 1;
            DB::table(self::TABLE)->where('id', $row->id)->update([
                'step'         => min($next, self::LAST_STEP),
                'status'       => $next > self::LAST_STEP ? 'done' : 'active',
                'next_at'      => $next > self::LAST_STEP ? null : Carbon::parse($row->base_at)->addDays(self::STEPS[$next][1]),
                'last_sent_at' => now(), 'last_sent' => $stage, 'sends' => $row->sends + 1, 'fails' => 0, 'updated_at' => now(),
                'note'         => $next > self::LAST_STEP ? '12 months without an order' : null,
            ]);
            DB::table(self::LOG)->insert(['user_uuid' => $row->user_uuid, 'email' => $row->email, 'stage' => $stage, 'step' => $row->step, 'cycle' => $row->cycle, 'ok' => true, 'sent_at' => now()]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Travo lifecycle email failed: ' . $e->getMessage(), ['user' => $row->user_uuid, 'stage' => $stage]);
            // mail server busy / per-connection or per-hour limit: not the address's fault; reconnect and retry soon
            $transient = (bool) preg_match('/\b(421|450|451|452)\b|limit exceeded|too many/i', $e->getMessage());
            if ($transient) {
                self::freshSmtp();
            }
            if (!$overrideTo) {
                $fails = $transient ? (int) $row->fails : $row->fails + 1;
                DB::table(self::TABLE)->where('id', $row->id)->update([
                    'fails' => $fails, 'status' => $fails >= 3 ? 'failed' : 'active', 'next_at' => $transient ? now()->addMinutes(30) : now()->addHours(6),
                    'note' => Str::limit($e->getMessage(), 180), 'updated_at' => now(),
                ]);
                DB::table(self::LOG)->insert(['user_uuid' => $row->user_uuid, 'email' => $row->email, 'stage' => $stage, 'step' => $row->step, 'cycle' => $row->cycle, 'ok' => false, 'error' => Str::limit($e->getMessage(), 250), 'sent_at' => now()]);
            }

            return false;
        }
    }

    /** Drops the open SMTP connection so the next email opens a new one. */
    public static function freshSmtp(): void
    {
        rescue(function () {
            Mail::purge(config('mail.default'));
            usleep(300000);
        }, null, false);
    }

    /** True if the email's domain has MX (or A) records. Cached per domain for a day. */
    public static function domainAcceptsMail(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '') {
            return false;
        }

        $ok = Cache::remember('travo_drip_mx_' . md5($domain), 86400, function () use ($domain) {
            return checkdnsrr($domain . '.', 'MX') || checkdnsrr($domain . '.', 'A');
        });
        if (!$ok && !checkdnsrr('gmail.com.', 'MX')) {
            Cache::forget('travo_drip_mx_' . md5($domain));   // DNS itself is down: don't blame the address

            return true;
        }

        return $ok;
    }

    // ---- unsubscribe ----------------------------------------------------------------------

    public static function unsubscribeCode(string $publicId): string
    {
        $mac = hash_hmac('sha256', 'travo-unsub|' . $publicId, (string) config('app.key'), true);

        return $publicId . '-' . substr(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), 0, 12);
    }

    public static function unsubscribeUrl(string $publicId): string
    {
        return self::UNSUB_BASE . self::unsubscribeCode($publicId);
    }

    /** The user for a valid code, or null. */
    public static function userFromCode(string $code): ?object
    {
        $publicId = strlen($code) > 13 && $code[strlen($code) - 13] === '-' ? substr($code, 0, -13) : '';
        if (!$publicId || !hash_equals(self::unsubscribeCode($publicId), $code)) {
            return null;
        }

        return DB::table('users')->where('public_id', $publicId)->first();
    }

    public static function unsubscribe(object $user): void
    {
        $now = now();
        $row = DB::table(self::TABLE)->where('user_uuid', $user->uuid)->first();
        if ($row) {
            DB::table(self::TABLE)->where('id', $row->id)->update(['status' => 'unsubscribed', 'unsubscribed_at' => $row->unsubscribed_at ?: $now, 'next_at' => null, 'updated_at' => $now]);
        } else {
            DB::table(self::TABLE)->insertOrIgnore(['user_uuid' => $user->uuid, 'email' => strtolower((string) $user->email), 'status' => 'unsubscribed', 'unsubscribed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /** Opting back in: carries on from where they were (or starts at the monthly emails). */
    public static function resubscribe(object $user): void
    {
        $row = DB::table(self::TABLE)->where('user_uuid', $user->uuid)->first();
        if (!$row || $row->status !== 'unsubscribed') {
            return;
        }
        $base = $row->base_at ? Carbon::parse($row->base_at) : now();
        $next = $base->copy()->addDays(self::STEPS[$row->step][1]);
        DB::table(self::TABLE)->where('id', $row->id)->update([
            'status' => $row->sends ? 'active' : 'queued', 'unsubscribed_at' => null,
            'next_at' => $row->sends ? ($next->lt(now()) ? now()->addDay() : $next) : null, 'updated_at' => now(),
        ]);
    }

    // ---- content ----------------------------------------------------------------------------

    public static function link(string $stage, string $what, string $url = 'https://travo.ng/auth'): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . 'utm_source=travo&utm_medium=email&utm_campaign=lifecycle-' . $stage . '&utm_content=' . rawurlencode($what);
    }

    /** Everything the email needs. $month: 1..12 for the month emails. */
    public static function emailData(string $stage, object $user, ?int $month = null, ?object $row = null): array
    {
        $first = trim(explode(' ', trim((string) $user->name))[0] ?? '') ?: null;
        if ($first && (strlen($first) < 2 || preg_match('/[@\d]/', $first))) {
            $first = null;
        }
        $first = $first ? Str::ucfirst(Str::lower($first)) : null;
        $s     = self::settings();
        $data  = [
            'stage'      => $stage,
            'month'      => $month,
            'first'      => $first,
            'cta'        => self::link($stage, 'cta'),
            'linkFor'    => fn ($what) => self::link($stage, $what),
            'unsub'      => self::unsubscribeUrl($user->public_id),
            'address'    => $s['address'],
            'whatsapp'   => 'https://wa.me/2349163352137',
            'individual' => self::SERVICES_INDIVIDUAL,
            'business'   => self::SERVICES_BUSINESS,
            'stats'      => in_array($stage, ['month', 'monthly'], true) ? self::stats() : null,
            'spotlight'  => self::spotlight($month ?: 0),
            'final'      => $stage === 'monthly' && $month === 12,
            'news'       => trim((string) $s['monthly_news']),
        ];
        $name = $first ? $first . ', ' : '';
        $data['subject'] = [
            'welcome'    => 'Welcome to Travo' . ($first ? ', ' . $first : '') . ': one account for everything you need moved',
            'free_quote' => $name . 'your first quote is free (and so is every one after it)',
            'week'       => 'How Nigerian businesses and families get things moved with Travo',
            'month'      => $name . 'here\'s what you missed on Travo this month',
            'monthly'    => 'Travo in ' . now(self::TZ)->subDays(15)->format('F') . ': ' . ($data['stats']['orders'] ?? 0) . ' orders and what\'s new',
        ][$stage];
        if ($data['final']) {
            $data['subject'] = $name . 'should we stop emailing you?';
        }
        $data['preheader'] = [
            'welcome'    => 'Parcels, documents, tickets, drivers, haulage, fuel and more, priced instantly and tracked end to end.',
            'free_quote' => 'Get an instant price in under a minute. No card, no commitment, and you can share it with your clients.',
            'week'       => 'A closer look at parcels and documents, travel, and business logistics on Travo.',
            'month'      => 'New members, real orders across Nigeria, and the services people booked most.',
            'monthly'    => 'The numbers, the most-booked services and a service worth knowing about.',
        ][$stage];

        return $data;
    }

    public const SERVICES_INDIVIDUAL = [
        ['📦', 'Send a parcel', 'Same-day across Lagos, next-day nationwide'],
        ['🚌', 'Bus & train tickets', 'Book intercity trips without the queue'],
        ['🚗', 'Car with driver', 'By the hour, the day or the occasion'],
        ['✈️', 'Airport pickup', 'Meet-and-greet when you land'],
        ['🛒', 'Food, essentials & market runs', 'From stores and major markets to your door'],
        ['🔥', 'Gas, water & diesel', 'Scheduled refills, delivered'],
        ['🧺', 'Laundry pickup', 'Collected and returned, home or office'],
        ['🛠️', 'Handyman, plumber, locksmith', 'Vetted help, booked in minutes'],
        ['🚨', 'Breakdown & towing', 'Fast help when the car lets you down'],
    ];

    public const SERVICES_BUSINESS = [
        ['📄', 'Document & courier runs', 'Contracts, tenders and samples, same day in Lagos, with proof of delivery'],
        ['🚚', 'Haulage', 'Vans and trucks for stock, equipment and moves'],
        ['🚐', 'Vehicle charter', 'Cars, buses and vans by the day, week or month'],
        ['⛽', 'Diesel & fuel delivery', 'For generators, sites and fleets'],
        ['🏗️', 'Machinery leasing', 'Heavy equipment, short or long term'],
        ['🚢', 'Import & export', 'Containers shipped end to end'],
        ['🧑‍✈️', 'Staff transport & airport runs', 'Drivers and pickups for your team and guests'],
        ['♻️', 'Skip hire & waste collection', 'Scheduled clearances for offices and sites'],
    ];

    /** A service to feature each month (rotates). */
    public static function spotlight(int $month): array
    {
        $list = [
            ['📄', 'Same-day document runs in Lagos', 'Contracts, tenders and samples collected and delivered the same day, with a tracking link for whoever is waiting and photos as proof of delivery.', 'documents'],
            ['🚚', 'Haulage without the haggling', 'Vans and trucks for stock, equipment and house moves, priced up front. Add loading help, insurance cover or temperature control when you book.', 'haulage'],
            ['⛽', 'Diesel on schedule', 'Keep generators and sites running with scheduled diesel, gas and water deliveries, so nobody has to chase a supplier.', 'fuel'],
            ['🚌', 'Intercity tickets in a few taps', 'Compare operators, book your bus or train seat and travel without the queue at the park.', 'travel'],
            ['🚗', 'A car and driver when you need one', 'Airport runs, meetings, events or a full day out: book a driver by the hour or the day.', 'driver'],
            ['🚢', 'Import & export, end to end', 'Ship a container in or out of Nigeria with one partner handling the moving parts.', 'import-export'],
            ['🛒', 'Market runs, done for you', 'Send us your list for the major markets and we collect and deliver, so you skip the traffic.', 'market'],
            ['🏗️', 'Machinery for the job', 'Excavators, loaders and other heavy equipment on short or long-term lease.', 'machinery'],
            ['🚐', 'Vehicles on charter', 'Cars, buses and vans by the day, week or month for projects, staff and events.', 'charter'],
            ['♻️', 'Skip hire & waste collection', 'Scheduled clearances for homes, offices and building sites.', 'waste'],
            ['✈️', 'Airport meet-and-greet', 'Someone waiting at arrivals with your name, for you, your family or your guests.', 'airport'],
            ['🧺', 'Laundry, collected and returned', 'Pickup and drop-off laundry from home or the office.', 'laundry'],
        ];

        return $list[$month % count($list)];
    }

    /**
     * Platform numbers for the last 30 days (cached 6 hours). Orders = all orders/quotes created (paid or not),
     * as agreed on 26 Oct. Samples show service, area-level route and price only.
     */
    public static function stats(): array
    {
        return Cache::remember('travo_drip_stats_v1_' . now()->format('Y-m-d-') . intdiv((int) now()->format('G'), 6), 6 * 3600, function () {
            $now   = now();
            $from  = $now->copy()->subDays(30);
            $prev  = $now->copy()->subDays(60);
            $users = DB::table('users')->whereNull('deleted_at');
            $total = (clone $users)->count();
            $new   = (clone $users)->where('created_at', '>=', $from)->count();
            $newPrev = (clone $users)->whereBetween('created_at', [$prev, $from])->count();
            $orders  = DB::table('orders')->whereNull('deleted_at');
            $o30     = (clone $orders)->where('created_at', '>=', $from)->count();
            $oPrev   = (clone $orders)->whereBetween('created_at', [$prev, $from])->count();

            $types = (clone $orders)->where('created_at', '>=', $from)
                ->select('type', DB::raw('count(*) as n'))->groupBy('type')->orderByDesc('n')->limit(5)->get();
            $top = [];
            foreach ($types as $t) {
                $top[] = ['name' => self::typeName($t->type), 'n' => $t->n];
            }

            $samples = [];
            $qc      = app(QuoteShareController::class);
            $recent  = Order::whereNull('deleted_at')->where('created_at', '>=', $from)
                ->whereNotIn('status', ['canceled', 'cancelled', 'expired'])
                ->with(['payload.pickup', 'payload.dropoff', 'payload.entities', 'purchaseRate.serviceQuote.items'])
                ->orderByDesc('created_at')->limit(40)->get();
            $seen = [];
            foreach ($recent as $o) {
                try {
                    $q = $qc->buildQuote($o, QuoteShareController::code($o->public_id));
                } catch (\Throwable $e) {
                    continue;
                }
                if (!$q['price'] || !$q['pickup'] || !$q['dropoff']) {
                    continue;
                }
                // a mix: no repeated route, at most 2 of the same service
                $key  = $q['orderType'] . '|' . $q['pickup']['label'] . '|' . $q['dropoff']['label'];
                $type = 't|' . $q['orderType'];
                if (isset($seen[$key]) || ($seen[$type] ?? 0) >= 2) {
                    continue;
                }
                $seen[$key]  = true;
                $seen[$type] = ($seen[$type] ?? 0) + 1;
                $samples[] = [
                    'type'  => Str::title(Str::lower($q['orderType'])),
                    'route' => $q['pickup']['label'] . ' → ' . $q['dropoff']['label'],
                    'price' => $q['price'],
                    'when'  => Carbon::parse($o->created_at)->timezone(self::TZ)->format('j M'),
                ];
                if (count($samples) >= 5) {
                    break;
                }
            }

            $pct = fn ($a, $b) => $b > 0 ? (int) round(($a - $b) / $b * 100) : null;

            return [
                'members'      => $total,
                'newMembers'   => $new,
                'membersGrowth' => $total - $new > 0 ? round($new / ($total - $new) * 100, 1) : null,
                'newVsPrev'    => $pct($new, $newPrev),
                'orders'       => $o30,
                'ordersVsPrev' => $pct($o30, $oPrev),
                'top'          => $top,
                'samples'      => $samples,
                'period'       => $from->timezone(self::TZ)->format('j M') . ' – ' . $now->timezone(self::TZ)->format('j M Y'),
            ];
        });
    }

    private static function typeName(?string $type): string
    {
        $name = DB::table('order_configs')->where('key', $type)->value('name');
        if (!$name) {
            $name = Str::headline((string) $type);
        }

        return Str::title(Str::lower(Str::limit($name, 40, '')));
    }
}
