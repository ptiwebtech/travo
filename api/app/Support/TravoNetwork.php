<?php

namespace App\Support;

use Fleetbase\FleetOps\Models\Order;
use App\Http\Controllers\OrderFlowController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Travo "My Network" (27 Oct 2026): personal invite links, invites the user chooses to send,
 * linked accounts, and referral commission.
 *
 * Rules (agreed with Martin):
 *  - Commission: 1% of the order total paid, on every PAID order by a friend who signed up through
 *    the member's link or invite, for 12 months from the friend's sign-up.
 *  - Only new sign-ups (accounts created after the launch and claimed within 7 days of sign-up).
 *    No self-referral: same person, same company, same phone number or same email are refused.
 *  - Commission waits 14 days (or until the order is delivered), then lands in the member's wallet.
 *    Canceled orders reverse it.
 *  - Invites go only to the people the member ticks. One invite and at most one reminder per person
 *    (the member presses "Remind"), every invite carries a "don't invite me again" link, and the list
 *    never reveals whether an address already has an account.
 *
 * Tables: travo_referral_codes, travo_network_invites, travo_referrals, travo_referral_earnings,
 * travo_email_suppression. Settings: storage/app/travo-network.json.
 */
class TravoNetwork
{
    public const CODES = 'travo_referral_codes';

    public const INVITES = 'travo_network_invites';

    public const REFERRALS = 'travo_referrals';

    public const EARNINGS = 'travo_referral_earnings';

    public const SUPPRESS = 'travo_email_suppression';

    public const LINK_BASE = 'https://travo.ng/r/';

    public const DEFAULTS = [
        'enabled'          => true,
        'invites_enabled'  => true,
        'launch_at'        => null,         // set by --install; only accounts created after it can be referred
        'commission_pct'   => 1,
        'months'           => 12,
        'pending_days'     => 14,
        'claim_days'       => 7,
        'min_payout'       => 500000,       // kobo (₦5,000)
        'min_topup'        => 100000,       // kobo (₦1,000)
        'invites_per_day'  => 50,           // per member
        'invites_per_run'  => 30,           // per 10-minute run (shared IONOS mailbox: 500/hour with the email calendar)
        'remind_after_days' => 3,
        'max_inviters_per_email' => 3,      // an address invited by more people than this in 30 days gets no more invites
        'onboard_url'      => 'https://travo.ng/onboard',
        'staff_email'      => 'hello@travoservices.com',
        'notify_staff'     => true,
    ];

    // ---- settings / install -------------------------------------------------------------------

    public static function settingsPath(): string
    {
        return storage_path('app/travo-network.json');
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

    public static function install(): void
    {
        if (!Schema::hasTable(self::CODES)) {
            Schema::create(self::CODES, function ($t) {
                $t->id();
                $t->char('user_uuid', 36)->unique();
                $t->string('code', 32)->unique();
                $t->unsignedInteger('clicks')->default(0);
                $t->timestamps();
            });
        }
        if (!Schema::hasTable(self::INVITES)) {
            Schema::create(self::INVITES, function ($t) {
                $t->id();
                $t->char('inviter_uuid', 36)->index();
                $t->string('email', 191)->index();
                $t->string('name', 120)->nullable();
                $t->string('source', 20)->default('paste');     // paste | csv | vcard | google
                $t->string('status', 20)->index();             // queued | sent | member | joined | opted_out | bounced | failed | limited
                $t->string('token', 40)->unique();
                $t->dateTime('sent_at')->nullable();
                $t->dateTime('reminded_at')->nullable();
                $t->dateTime('remind_requested_at')->nullable();
                $t->dateTime('clicked_at')->nullable();
                $t->char('joined_user_uuid', 36)->nullable();
                $t->string('error', 191)->nullable();
                $t->timestamps();
                $t->unique(['inviter_uuid', 'email']);
            });
        }
        if (!Schema::hasTable(self::REFERRALS)) {
            Schema::create(self::REFERRALS, function ($t) {
                $t->id();
                $t->char('referrer_uuid', 36)->index();
                $t->char('referred_uuid', 36)->unique();
                $t->char('referred_company_uuid', 36)->nullable()->index();
                $t->string('source', 20);                       // link | invite | invite_email | staff
                $t->unsignedBigInteger('invite_id')->nullable();
                $t->dateTime('signed_up_at');
                $t->dateTime('expires_at');
                $t->string('status', 20)->default('active');    // active | blocked
                $t->string('note', 191)->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable(self::EARNINGS)) {
            Schema::create(self::EARNINGS, function ($t) {
                $t->id();
                $t->unsignedBigInteger('referral_id')->index();
                $t->char('referrer_uuid', 36)->index();
                $t->char('order_uuid', 36)->unique();           // one commission per order, ever
                $t->string('order_public_id', 40);
                $t->bigInteger('order_total');                  // kobo
                $t->bigInteger('amount');                       // kobo
                $t->decimal('pct', 5, 2);
                $t->string('status', 20)->index();              // pending | available | reversed
                $t->dateTime('paid_at');
                $t->dateTime('release_at');
                $t->dateTime('released_at')->nullable();
                $t->dateTime('reversed_at')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable(self::SUPPRESS)) {
            Schema::create(self::SUPPRESS, function ($t) {
                $t->id();
                $t->string('email', 191)->unique();
                $t->string('reason', 30);                       // opted_out | bounced | staff
                $t->timestamps();
            });
        }
        $s = self::settings();
        if (empty($s['launch_at'])) {
            $s['launch_at'] = now()->toIso8601String();
        }
        self::saveSettings($s);
        TravoWallet::install();
    }

    // ---- codes & links ------------------------------------------------------------------------

    public static function codeFor(object $user): string
    {
        $row = DB::table(self::CODES)->where('user_uuid', $user->uuid)->first();
        if ($row) {
            return $row->code;
        }
        $base = Str::lower(preg_replace('/[^A-Za-z]/', '', Str::ascii(explode(' ', trim((string) $user->name))[0] ?? ''))) ?: 'travo';
        $base = substr($base, 0, 12);
        for ($i = 0; $i < 20; $i++) {
            $code = $base . '-' . Str::lower(Str::random(4));
            $code = preg_replace('/[^a-z0-9-]/', 'x', $code);
            if (DB::table(self::CODES)->insertOrIgnore(['user_uuid' => $user->uuid, 'code' => $code, 'created_at' => now(), 'updated_at' => now()])) {
                break;
            }
        }

        return DB::table(self::CODES)->where('user_uuid', $user->uuid)->value('code');
    }

    public static function link(string $code, ?string $token = null): string
    {
        return self::LINK_BASE . $code . ($token ? '?i=' . $token : '');
    }

    public static function optOutUrl(string $code, string $token): string
    {
        return self::LINK_BASE . $code . '/no/' . $token;
    }

    /** Landing on /r/{code}: count the click, note the invite, and send them to sign-up with the code. */
    public static function landing(string $code, ?string $token): ?string
    {
        $row = DB::table(self::CODES)->where('code', Str::lower($code))->first();
        if (!$row) {
            return null;
        }
        DB::table(self::CODES)->where('id', $row->id)->increment('clicks');
        $q = ['ref' => $row->code];
        if ($token && ($inv = DB::table(self::INVITES)->where('token', $token)->where('inviter_uuid', $row->user_uuid)->first())) {
            if (!$inv->clicked_at) {
                DB::table(self::INVITES)->where('id', $inv->id)->update(['clicked_at' => now(), 'updated_at' => now()]);
            }
            $q['i'] = $token;
        }

        return self::settings()['onboard_url'] . '?' . http_build_query($q);
    }

    // ---- suppression --------------------------------------------------------------------------

    public static function suppressed(string $email): bool
    {
        return DB::table(self::SUPPRESS)->where('email', Str::lower($email))->exists();
    }

    public static function suppress(string $email, string $reason): void
    {
        $email = Str::lower(trim($email));
        DB::table(self::SUPPRESS)->insertOrIgnore(['email' => $email, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
        DB::table(self::INVITES)->where('email', $email)->whereIn('status', ['queued', 'sent'])
            ->update(['status' => $reason === 'bounced' ? 'bounced' : 'opted_out', 'updated_at' => now()]);
    }

    public static function inviteFromOptOut(string $code, string $token): ?object
    {
        $c = DB::table(self::CODES)->where('code', Str::lower($code))->first();

        return $c ? DB::table(self::INVITES)->where('token', $token)->where('inviter_uuid', $c->user_uuid)->first() : null;
    }

    // ---- invites ------------------------------------------------------------------------------

    /**
     * Adds the contacts the member ticked. $contacts = [['email' => .., 'name' => ..], ..].
     * Returns counts only (never says which addresses already have accounts).
     */
    public static function addInvites(object $user, array $contacts, string $source = 'paste'): array
    {
        $s   = self::settings();
        $out = ['added' => 0, 'already_invited' => 0, 'invalid' => 0, 'skipped' => 0, 'over_limit' => 0, 'limit' => $s['invites_per_day']];
        if (!$s['enabled'] || !$s['invites_enabled']) {
            return $out + ['error' => 'Invites are paused at the moment.'];
        }
        $source = in_array($source, ['paste', 'csv', 'vcard', 'google'], true) ? $source : 'paste';
        $today  = DB::table(self::INVITES)->where('inviter_uuid', $user->uuid)->where('created_at', '>=', now()->subDay())->count();
        $room   = max(0, (int) $s['invites_per_day'] - $today);
        $seen   = [];
        $own    = Str::lower((string) $user->email);

        foreach (array_slice($contacts, 0, 500) as $c) {
            $email = Str::lower(trim((string) ($c['email'] ?? '')));
            $name  = Str::limit(trim(strip_tags((string) ($c['name'] ?? ''))), 110, '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) {
                $out[isset($seen[$email]) ? 'already_invited' : 'invalid']++;
                continue;
            }
            $seen[$email] = true;
            if ($email === $own || self::suppressed($email) || str_ends_with($email, '@travoservices.com')) {
                $out['skipped']++;
                continue;
            }
            if (DB::table(self::INVITES)->where('inviter_uuid', $user->uuid)->where('email', $email)->exists()) {
                $out['already_invited']++;
                continue;
            }
            if ($room <= 0) {
                $out['over_limit']++;
                continue;
            }
            // nobody gets invites from more than N different people in 30 days
            $others = DB::table(self::INVITES)->where('email', $email)->where('created_at', '>=', now()->subDays(30))->count();
            $status = $others >= (int) $s['max_inviters_per_email'] ? 'limited' : 'queued';
            DB::table(self::INVITES)->insertOrIgnore([
                'inviter_uuid' => $user->uuid, 'email' => $email, 'name' => $name ?: null, 'source' => $source, 'status' => $status,
                'token' => Str::random(32), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $out['added']++;
            $room--;
        }

        return $out;
    }

    /** The member asks for the one reminder. Sent by the next run. */
    public static function requestReminder(object $user, int $inviteId): array
    {
        $inv = DB::table(self::INVITES)->where('id', $inviteId)->where('inviter_uuid', $user->uuid)->first();
        if (!$inv) {
            return ['ok' => false, 'error' => 'Invite not found.'];
        }
        $days = (int) self::settings()['remind_after_days'];
        if ($inv->reminded_at || $inv->remind_requested_at) {
            return ['ok' => false, 'error' => 'You have already sent a reminder to this person.'];
        }
        if (in_array($inv->status, ['joined', 'opted_out', 'bounced', 'failed'], true)) {
            return ['ok' => false, 'error' => 'This invite can no longer be reminded.'];
        }
        if (!$inv->sent_at && $inv->status !== 'member' && $inv->status !== 'limited') {
            return ['ok' => false, 'error' => 'The invite has not gone out yet.'];
        }
        $since = $inv->sent_at ?: $inv->updated_at;
        if (Carbon::parse($since)->gt(now()->subDays($days))) {
            return ['ok' => false, 'error' => 'You can send a reminder ' . $days . ' days after the invite.'];
        }
        DB::table(self::INVITES)->where('id', $inv->id)->update(['remind_requested_at' => now(), 'updated_at' => now()]);

        return ['ok' => true];
    }

    /** How an invite looks to the member. "member" (already has an account) looks the same as "sent". */
    public static function inviteStatusLabel(object $inv): string
    {
        switch ($inv->status) {
            case 'queued':    return 'Sending';
            case 'joined':    return 'Joined';
            case 'opted_out': return 'Declined invites';
            case 'bounced':   return 'Email bounced';
            case 'failed':    return 'Could not send';
            default:          return ($inv->reminded_at || $inv->remind_requested_at) ? 'Reminded' : 'Invited';
        }
    }

    // ---- attribution --------------------------------------------------------------------------

    private static function digits(?string $phone): string
    {
        $d = preg_replace('/\D/', '', (string) $phone);

        return strlen($d) >= 10 ? substr($d, -10) : '';
    }

    /**
     * Links a new account to the member whose code it signed up with.
     * Returns ['ok' => bool, 'error' => ?string]. Errors are worded for the new user, without detail.
     */
    public static function claim(object $user, string $code, ?string $token = null, string $source = 'link'): array
    {
        $s = self::settings();
        if (!$s['enabled']) {
            return ['ok' => false, 'error' => 'paused'];
        }
        if (DB::table(self::REFERRALS)->where('referred_uuid', $user->uuid)->exists()) {
            return ['ok' => false, 'error' => 'already linked'];
        }
        $codeRow = DB::table(self::CODES)->where('code', Str::lower(trim($code)))->first();
        if (!$codeRow) {
            return ['ok' => false, 'error' => 'unknown code'];
        }
        $referrer = DB::table('users')->where('uuid', $codeRow->user_uuid)->whereNull('deleted_at')->first();
        if (!$referrer) {
            return ['ok' => false, 'error' => 'unknown code'];
        }
        $created = Carbon::parse($user->created_at);
        if (($s['launch_at'] && $created->lt(Carbon::parse($s['launch_at']))) || $created->lt(now()->subDays((int) $s['claim_days']))) {
            return ['ok' => false, 'error' => 'existing account'];
        }
        $block = null;
        if ($referrer->uuid === $user->uuid || Str::lower((string) $referrer->email) === Str::lower((string) $user->email)) {
            $block = 'same person';
        } elseif ($referrer->company_uuid && $referrer->company_uuid === $user->company_uuid) {
            $block = 'same company';
        } elseif (($p = self::digits($user->phone ?? null)) && $p === self::digits($referrer->phone ?? null)) {
            $block = 'same phone';
        } elseif (DB::table(self::REFERRALS)->where('referrer_uuid', $user->uuid)->where('referred_uuid', $referrer->uuid)->exists()) {
            $block = 'circular';
        }
        if ($block) {
            Log::info('Travo referral refused', ['user' => $user->public_id ?? $user->uuid, 'code' => $codeRow->code, 'reason' => $block]);

            return ['ok' => false, 'error' => 'not eligible'];
        }

        $invite = null;
        if ($token) {
            $invite = DB::table(self::INVITES)->where('token', $token)->where('inviter_uuid', $referrer->uuid)->first();
        }
        if (!$invite) {
            $invite = DB::table(self::INVITES)->where('inviter_uuid', $referrer->uuid)->where('email', Str::lower((string) $user->email))->first();
        }
        $company = self::ownCompany($user);
        DB::table(self::REFERRALS)->insertOrIgnore([
            'referrer_uuid' => $referrer->uuid, 'referred_uuid' => $user->uuid, 'referred_company_uuid' => $company,
            'source' => $invite ? ($source === 'invite_email' ? 'invite_email' : 'invite') : 'link', 'invite_id' => optional($invite)->id,
            'signed_up_at' => $created, 'expires_at' => $created->copy()->addMonths((int) $s['months']),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($invite) {
            DB::table(self::INVITES)->where('id', $invite->id)->update(['status' => 'joined', 'joined_user_uuid' => $user->uuid, 'updated_at' => now()]);
        }

        return ['ok' => true, 'referrer' => $referrer->name];
    }

    /** The user's own company (one they own), so orders placed by colleagues they add also count. */
    private static function ownCompany(object $user): ?string
    {
        if (!$user->company_uuid) {
            return null;
        }
        $c = DB::table('companies')->where('uuid', $user->company_uuid)->first(['uuid', 'owner_uuid']);

        return $c && $c->owner_uuid === $user->uuid ? $c->uuid : null;
    }

    /** New accounts whose email was invited but who did not come through the link: link them to the first inviter. */
    public static function matchInvitedSignups(): int
    {
        $s = self::settings();
        if (!$s['launch_at']) {
            return 0;
        }
        $n     = 0;
        $users = DB::table('users')->whereNull('deleted_at')
            ->where('created_at', '>=', max(Carbon::parse($s['launch_at']), now()->subDays((int) $s['claim_days'])))
            ->whereNotIn('uuid', DB::table(self::REFERRALS)->select('referred_uuid'))
            ->get();
        foreach ($users as $u) {
            $inv = DB::table(self::INVITES)->where('email', Str::lower((string) $u->email))->whereIn('status', ['sent', 'queued', 'limited'])->whereNotNull('sent_at')->orderBy('sent_at')->first();
            if ($inv && ($code = DB::table(self::CODES)->where('user_uuid', $inv->inviter_uuid)->value('code'))) {
                $r = self::claim($u, $code, $inv->token, 'invite_email');
                $n += $r['ok'] ? 1 : 0;
            }
        }

        return $n;
    }

    // ---- commission ---------------------------------------------------------------------------

    /** Orders that belong to a referred user: they created it, they own its company, or they are its customer contact. */
    private static function ordersFor(object $ref)
    {
        $contacts = DB::table('contacts')->where('user_uuid', $ref->referred_uuid)->whereNull('deleted_at')->pluck('uuid')->all();

        return DB::table('orders')->whereNull('deleted_at')
            ->where('created_at', '>=', $ref->signed_up_at)
            ->where(function ($q) use ($ref, $contacts) {
                $q->where('created_by_uuid', $ref->referred_uuid)->orWhere('customer_uuid', $ref->referred_uuid);
                if ($ref->referred_company_uuid) {
                    $q->orWhere('company_uuid', $ref->referred_company_uuid);
                }
                if ($contacts) {
                    $q->orWhereIn('customer_uuid', $contacts);
                }
            })
            ->whereNotIn('uuid', DB::table(self::EARNINGS)->select('order_uuid'))
            ->whereNotIn('status', ['canceled', 'cancelled'])
            ->orderBy('created_at')
            ->limit(200)
            ->get(['uuid']);
    }

    /** When the order was paid (Travo flow time, else first PAYMENT_RECEIVED tracking time, else last update), or null. */
    public static function paidAt(Order $order): ?Carbon
    {
        $state = OrderFlowController::state($order);
        foreach ($state['steps'] as $st) {
            if ($st['key'] === 'paid') {
                if (!$st['done']) {
                    return null;
                }

                return $st['at'] ? Carbon::parse($st['at']) : Carbon::parse($order->updated_at);
            }
        }

        return null;
    }

    /** Finds newly paid orders by referred users and records commission (pending). */
    public static function recordEarnings(bool $dry = false): array
    {
        $s   = self::settings();
        $out = ['checked' => 0, 'recorded' => 0, 'amount' => 0, 'skipped' => []];
        $refs = DB::table(self::REFERRALS)->where('status', 'active')->where('expires_at', '>=', now()->subDays(60))->get();
        foreach ($refs as $ref) {
            foreach (self::ordersFor($ref) as $row) {
                $out['checked']++;
                $order = Order::where('uuid', $row->uuid)->first();
                if (!$order) {
                    continue;
                }
                $paidAt = self::paidAt($order);
                if (!$paidAt) {
                    continue;   // not paid yet: looked at again next run
                }
                if ($paidAt->lt(Carbon::parse($ref->signed_up_at)) || $paidAt->gt(Carbon::parse($ref->expires_at))) {
                    $out['skipped'][] = $order->public_id . ' paid outside the 12 months';
                    if (!$dry) {
                        self::recordZero($ref, $order, $paidAt, 'outside window');
                    }
                    continue;
                }
                // an order paid from the referrer's own wallet earns the referrer nothing
                $walletRef = data_get($order->meta, 'travo_flow.paid.wallet');
                if ($walletRef && DB::table(TravoWallet::ENTRIES)->where('public_id', $walletRef)->where('user_uuid', $ref->referrer_uuid)->exists()) {
                    if (!$dry) {
                        self::recordZero($ref, $order, $paidAt, 'paid by referrer');
                    }
                    continue;
                }
                $total = (int) (data_get($order->meta, 'travo_flow.paid.amount') ?: (TravoWallet::amountDue($order) ?? 0));
                $amount = (int) round($total * ((float) $s['commission_pct']) / 100);
                if ($amount <= 0) {
                    $out['skipped'][] = $order->public_id . ' has no price';
                    continue;   // priced later? looked at again next run
                }
                $out['recorded']++;
                $out['amount'] += $amount;
                if ($dry) {
                    continue;
                }
                DB::transaction(function () use ($ref, $order, $paidAt, $total, $amount, $s) {
                    $release = $paidAt->copy()->addDays((int) $s['pending_days']);
                    $id      = DB::table(self::EARNINGS)->insertGetId([
                        'referral_id' => $ref->id, 'referrer_uuid' => $ref->referrer_uuid, 'order_uuid' => $order->uuid, 'order_public_id' => $order->public_id,
                        'order_total' => $total, 'amount' => $amount, 'pct' => $s['commission_pct'], 'status' => 'pending',
                        'paid_at' => $paidAt, 'release_at' => $release, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $name = DB::table('users')->where('uuid', $ref->referred_uuid)->value('name');
                    DB::table(TravoWallet::ENTRIES)->insert([
                        'public_id' => TravoWallet::ref(), 'user_uuid' => $ref->referrer_uuid, 'type' => 'commission', 'amount' => $amount, 'status' => 'pending',
                        'description' => 'Commission: ' . ($s['commission_pct'] + 0) . '% of an order by ' . (self::firstName($name) ?: 'your friend'),
                        'order_uuid' => $order->uuid, 'order_public_id' => $order->public_id, 'earning_id' => $id,
                        'meta' => json_encode(['release_at' => $release->toIso8601String()]), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                });
            }
        }

        return $out;
    }

    /** Remembers an order that earns nothing, so it isn't looked at every run. */
    private static function recordZero(object $ref, Order $order, Carbon $paidAt, string $why): void
    {
        DB::table(self::EARNINGS)->insertOrIgnore([
            'referral_id' => $ref->id, 'referrer_uuid' => $ref->referrer_uuid, 'order_uuid' => $order->uuid, 'order_public_id' => $order->public_id,
            'order_total' => 0, 'amount' => 0, 'pct' => 0, 'status' => 'reversed', 'paid_at' => $paidAt, 'release_at' => $paidAt,
            'reversed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Log::info('Travo referral: no commission', ['order' => $order->public_id, 'why' => $why]);
    }

    private static function firstName(?string $name): string
    {
        return trim(explode(' ', trim((string) $name))[0] ?? '');
    }

    /** Releases pending commission (delivered, or past the waiting time) and reverses canceled orders. */
    public static function settleEarnings(): array
    {
        $out = ['released' => 0, 'reversed' => 0];
        $rows = DB::table(self::EARNINGS)->whereIn('status', ['pending', 'available'])->where('amount', '>', 0)
            ->where(function ($q) {
                $q->where('status', 'pending')->orWhere('updated_at', '>=', now()->subDays(60));   // watch released ones for 60 days
            })->get();
        foreach ($rows as $e) {
            $order = DB::table('orders')->where('uuid', $e->order_uuid)->first(['status', 'deleted_at']);
            $canceled = !$order || $order->deleted_at || in_array(strtolower((string) $order->status), ['canceled', 'cancelled'], true);
            if ($canceled) {
                DB::transaction(function () use ($e) {
                    $entry = DB::table(TravoWallet::ENTRIES)->where('earning_id', $e->id)->where('type', 'commission')->where('amount', '>', 0)->first();
                    if ($e->status === 'pending' && $entry) {
                        DB::table(TravoWallet::ENTRIES)->where('id', $entry->id)->update(['status' => 'reversed', 'description' => $entry->description . ' (order canceled)', 'updated_at' => now()]);
                    } elseif ($e->status === 'available') {
                        DB::table(TravoWallet::ENTRIES)->insert([
                            'public_id' => TravoWallet::ref(), 'user_uuid' => $e->referrer_uuid, 'type' => 'commission', 'amount' => -$e->amount, 'status' => 'posted',
                            'description' => 'Commission reversed: order canceled', 'order_uuid' => $e->order_uuid, 'order_public_id' => $e->order_public_id,
                            'earning_id' => $e->id, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    DB::table(self::EARNINGS)->where('id', $e->id)->update(['status' => 'reversed', 'reversed_at' => now(), 'updated_at' => now()]);
                });
                $out['reversed']++;
                continue;
            }
            if ($e->status === 'pending' && (Carbon::parse($e->release_at)->lte(now()) || strtolower((string) $order->status) === 'completed')) {
                DB::transaction(function () use ($e) {
                    DB::table(TravoWallet::ENTRIES)->where('earning_id', $e->id)->where('type', 'commission')->where('status', 'pending')
                        ->update(['status' => 'posted', 'posted_at' => now(), 'updated_at' => now()]);
                    DB::table(self::EARNINGS)->where('id', $e->id)->update(['status' => 'available', 'released_at' => now(), 'updated_at' => now()]);
                });
                $out['released']++;
            }
        }

        return $out;
    }

    // ---- sending invites ----------------------------------------------------------------------

    public static function sendInvites(bool $dry = false): array
    {
        $s   = self::settings();
        $out = ['sent' => 0, 'reminded' => 0, 'member' => 0, 'failed' => 0];
        if (!$s['enabled'] || !$s['invites_enabled']) {
            return $out + ['paused' => true];
        }
        $budget = (int) $s['invites_per_run'];
        $sent   = 0;

        // reminders first (the member asked for them)
        $reminders = DB::table(self::INVITES)->whereNotNull('remind_requested_at')->whereNull('reminded_at')
            ->whereIn('status', ['sent', 'member', 'limited'])->orderBy('remind_requested_at')->limit($budget)->get();
        $queued = DB::table(self::INVITES)->where('status', 'queued')->orderBy('id')->limit($budget)->get();

        foreach ([['remind', $reminders], ['invite', $queued]] as [$kind, $rows]) {
            foreach ($rows as $inv) {
                if ($budget <= 0) {
                    break 2;
                }
                $isMember = DB::table('users')->where('email', $inv->email)->whereNull('deleted_at')->exists();
                if ($isMember || $inv->status === 'limited' || self::suppressed($inv->email)) {
                    // nothing is sent; to the inviter it still reads "Invited" / "Reminded"
                    if (!$dry) {
                        DB::table(self::INVITES)->where('id', $inv->id)->update([
                            'status' => self::suppressed($inv->email) ? 'opted_out' : ($isMember ? 'member' : $inv->status),
                            $kind === 'remind' ? 'reminded_at' : 'updated_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    $out['member']++;
                    continue;
                }
                if (!TravoDrip::domainAcceptsMail($inv->email)) {
                    if (!$dry) {
                        DB::table(self::INVITES)->where('id', $inv->id)->update(['status' => 'failed', 'error' => 'email domain does not accept mail', 'updated_at' => now()]);
                    }
                    $out['failed']++;
                    continue;
                }
                $budget--;
                if ($dry) {
                    $out[$kind === 'remind' ? 'reminded' : 'sent']++;
                    continue;
                }
                $ok = self::sendInviteEmail($inv, $kind === 'remind');
                if ((++$sent) % 10 === 0) {
                    TravoDrip::freshSmtp();   // IONOS: limited emails per SMTP connection
                }
                if ($ok === true) {
                    DB::table(self::INVITES)->where('id', $inv->id)->update($kind === 'remind'
                        ? ['reminded_at' => now(), 'updated_at' => now()]
                        : ['status' => 'sent', 'sent_at' => now(), 'error' => null, 'updated_at' => now()]);
                    $out[$kind === 'remind' ? 'reminded' : 'sent']++;
                } else {
                    DB::table(self::INVITES)->where('id', $inv->id)->update(['status' => $kind === 'remind' ? $inv->status : 'failed', 'reminded_at' => $kind === 'remind' ? now() : null, 'error' => Str::limit((string) $ok, 180), 'updated_at' => now()]);
                    $out['failed']++;
                }
            }
        }

        return $out;
    }

    /** Builds the invite (or reminder) email. Returns true or an error string. Test sends pass $to. */
    public static function sendInviteEmail(object $inv, bool $reminder = false, ?string $to = null)
    {
        $inviter = DB::table('users')->where('uuid', $inv->inviter_uuid)->first();
        if (!$inviter) {
            return 'inviter gone';
        }
        $code = self::codeFor($inviter);
        $data = [
            'inviterName' => trim((string) $inviter->name) ?: 'A friend',
            'inviterFirst' => self::firstName($inviter->name) ?: 'Your friend',
            'name'        => self::firstName($inv->name),
            'reminder'    => $reminder,
            'joinUrl'     => self::link($code, $inv->token),
            'optOutUrl'   => self::optOutUrl($code, $inv->token),
            'address'     => TravoDrip::settings()['address'] ?? 'Travo Services Limited, Lagos, Nigeria',
            'whatsapp'    => OrderFlowController::WHATSAPP,
        ];
        $data['subject'] = $reminder
            ? 'Reminder: ' . $data['inviterFirst'] . ' invited you to Travo'
            : $data['inviterName'] . ' invited you to Travo';
        try {
            Mail::send('emails.travo.network-invite', $data, function ($m) use ($inv, $data, $to) {
                $m->to($to ?: $inv->email, $inv->name ?: null)->subject($data['subject']);
                $m->replyTo('hello@travoservices.com', 'Travo');
                if ($rp = TravoBounce::returnPath()) {
                    $m->returnPath($rp);
                }
                $h = $m->getSymfonyMessage()->getHeaders();
                $h->addTextHeader('List-Unsubscribe', '<' . $data['optOutUrl'] . '>');
                $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('Travo invite email failed: ' . $e->getMessage(), ['invite' => $inv->id]);

            return $e->getMessage();
        }
    }

    /** Internal note to staff (top-up / payout requests). */
    public static function notifyStaff(string $subject, array $lines): void
    {
        $s = self::settings();
        if (!$s['notify_staff'] || !$s['staff_email']) {
            return;
        }
        rescue(function () use ($s, $subject, $lines) {
            Mail::raw(implode("\n", $lines) . "\n\nReview it in the console: https://travo.ng/fleet-ops (Wallet admin).", function ($m) use ($s, $subject) {
                $m->to($s['staff_email'])->subject('[Travo wallet] ' . $subject);
            });
        }, null, true);
    }

    // ---- the 10-minute job --------------------------------------------------------------------

    public static function run(bool $dry = false): array
    {
        $s = self::settings();
        if (!$s['enabled']) {
            return ['enabled' => false];
        }
        $out = ['matched' => $dry ? 0 : self::matchInvitedSignups()];
        $out['earnings'] = self::recordEarnings($dry);
        if (!$dry) {
            $out['settled'] = self::settleEarnings();
        }
        if (!$dry) {
            $out['refunds'] = TravoWallet::refundCanceled();
        }
        $out['invites'] = self::sendInvites($dry);

        return $out;
    }

    // ---- what the member sees -----------------------------------------------------------------

    public static function overview(object $user): array
    {
        $s    = self::settings();
        $code = self::codeFor($user);

        $invites = DB::table(self::INVITES)->where('inviter_uuid', $user->uuid)->orderByDesc('id')->limit(500)->get();
        $refs    = DB::table(self::REFERRALS)->where('referrer_uuid', $user->uuid)->where('status', 'active')->orderByDesc('signed_up_at')->get();
        $earn    = DB::table(self::EARNINGS)->where('referrer_uuid', $user->uuid)->where('amount', '>', 0)->orderByDesc('paid_at')->limit(200)->get();
        $byRef   = $earn->groupBy('referral_id');
        $names   = DB::table('users')->whereIn('uuid', $refs->pluck('referred_uuid'))->pluck('name', 'uuid');
        $days    = (int) $s['remind_after_days'];

        $members = $refs->map(function ($r) use ($names, $byRef) {
            $mine = $byRef->get($r->id, collect());

            return [
                'name'       => $names[$r->referred_uuid] ?? 'Travo member',
                'joined_at'  => Carbon::parse($r->signed_up_at)->toIso8601String(),
                'earns_until' => Carbon::parse($r->expires_at)->toIso8601String(),
                'active'     => Carbon::parse($r->expires_at)->isFuture(),
                'orders'     => $mine->where('status', '!=', 'reversed')->count(),
                'earned'     => (int) $mine->where('status', 'available')->sum('amount'),
                'pending'    => (int) $mine->where('status', 'pending')->sum('amount'),
            ];
        })->values();

        $earnings = $earn->map(function ($e) use ($refs, $names) {
            $r = $refs->firstWhere('id', $e->referral_id);

            return [
                'friend'     => self::firstName($r ? ($names[$r->referred_uuid] ?? '') : '') ?: 'Friend',
                'order_total' => (int) $e->order_total,
                'amount'     => (int) $e->amount,
                'status'     => $e->status,
                'paid_at'    => Carbon::parse($e->paid_at)->toIso8601String(),
                'release_at' => Carbon::parse($e->release_at)->toIso8601String(),
            ];
        })->values();

        return [
            'code'     => $code,
            'link'     => self::link($code),
            'rules'    => ['pct' => $s['commission_pct'] + 0, 'months' => (int) $s['months'], 'pending_days' => (int) $s['pending_days'], 'min_payout' => (int) $s['min_payout'], 'invites_per_day' => (int) $s['invites_per_day']],
            'enabled'  => (bool) $s['enabled'] && (bool) $s['invites_enabled'],
            'stats'    => [
                'clicks'   => (int) DB::table(self::CODES)->where('user_uuid', $user->uuid)->value('clicks'),
                'invited'  => $invites->count(),
                'joined'   => $refs->count(),
                'earned'   => (int) $earn->where('status', 'available')->sum('amount'),
                'pending'  => (int) $earn->where('status', 'pending')->sum('amount'),
                'this_month' => (int) $earn->where('status', '!=', 'reversed')->filter(fn ($e) => Carbon::parse($e->paid_at)->gte(now('Africa/Lagos')->startOfMonth()))->sum('amount'),
            ],
            'invites'  => $invites->map(fn ($i) => [
                'id'         => $i->id,
                'email'      => $i->email,
                'name'       => $i->name,
                'status'     => self::inviteStatusLabel($i),
                'invited_at' => Carbon::parse($i->created_at)->toIso8601String(),
                'can_remind' => !$i->reminded_at && !$i->remind_requested_at && in_array($i->status, ['sent', 'member', 'limited'], true)
                    && Carbon::parse($i->sent_at ?: $i->updated_at)->lte(now()->subDays($days)),
            ])->values(),
            'members'  => $members,
            'earnings' => $earnings,
            'referred_by' => optional(DB::table(self::REFERRALS)->where('referred_uuid', $user->uuid)->first(), function ($r) {
                return DB::table('users')->where('uuid', $r->referrer_uuid)->value('name');
            }),
        ];
    }
}
