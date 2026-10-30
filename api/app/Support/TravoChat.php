<?php

namespace App\Support;

use App\Http\Controllers\QuoteShareController;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Travo chat (27 Oct 2026), on Fleet-Ops' own chat tables (chat_channels / chat_participants / chat_messages /
 * chat_attachments / files), so history is kept and nothing new is needed in the database except read markers.
 *
 *  - "Chat with Travo Support": one conversation per customer, with the support login(s) added automatically
 *    (setting support_users). Staff (admins) see every support conversation and can reply.
 *  - Direct chats only between people who are connected: My Network (travo_referrals, either direction) or a
 *    quote shared between the two accounts (order meta "Shared With Details"). Staff can message anyone.
 *  - Files and photos (10 MB max), stored privately; links are signed, so only people in the chat get them.
 *  - Quotes: a message can carry a quote card ([[quote:ORDER_PUBLIC_ID]]); direct chats also show the quotes
 *    the two accounts have shared with each other.
 *  - If a customer's support message gets no staff reply within 5 minutes, hello@ is emailed (at most every 30 min
 *    per conversation).
 *
 * Settings: storage/app/travo-chat.json. Table: travo_chat_reads.
 */
class TravoChat
{
    public const READS = 'travo_chat_reads';

    public const DEFAULTS = [
        'support_users'   => ['user_cmm3oWd'],      // public_ids of the logins that answer support chats
        'support_name'    => 'Travo Support',
        'alert_email'     => 'hello@travoservices.com',
        'alert_after_min' => 5,
        'alert_every_min' => 30,
        'max_file_mb'     => 10,
        // customer emails: "you have a new message" (27 Oct 2026)
        'notify_email'    => true,
        'notify_hours'    => 24,     // at most one email per conversation per person in this many hours
        'notify_delay_min'=> 2,      // wait this long first; if they read it in the app meanwhile, no email
        'notify_since'    => null,   // only messages after this time (set on install, so old messages never email)
    ];

    public const EMAILS = 'travo_chat_emails';

    public const EMAIL_OFF = 'travo_chat_email_off';

    public const FILE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'ppt', 'pptx', 'zip'];

    // ---- settings / install -------------------------------------------------------------------

    public static function settings(): array
    {
        $p = storage_path('app/travo-chat.json');
        $s = is_file($p) ? json_decode((string) file_get_contents($p), true) : [];

        return array_merge(self::DEFAULTS, is_array($s) ? $s : []);
    }

    public static function saveSettings(array $s): void
    {
        file_put_contents(storage_path('app/travo-chat.json'), json_encode(array_merge(self::settings(), $s), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function install(): void
    {
        if (!Schema::hasTable(self::READS)) {
            Schema::create(self::READS, function ($t) {
                $t->id();
                $t->string('channel_uuid', 64);
                $t->string('user_uuid', 64);
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
                $t->unique(['channel_uuid', 'user_uuid']);
            });
        }
        if (!Schema::hasTable(self::EMAILS)) {
            Schema::create(self::EMAILS, function ($t) {
                $t->id();
                $t->string('channel_uuid', 64);
                $t->string('user_uuid', 64);
                $t->unsignedBigInteger('message_id');
                $t->string('email', 191);
                $t->timestamp('emailed_at');
                $t->index(['channel_uuid', 'user_uuid', 'emailed_at']);
            });
        }
        if (!Schema::hasTable(self::EMAIL_OFF)) {
            Schema::create(self::EMAIL_OFF, function ($t) {
                $t->string('user_uuid', 64)->primary();
                $t->timestamp('created_at')->nullable();
            });
        }
        if (!self::settings()['notify_since']) {
            self::saveSettings(['notify_since' => now()->toDateTimeString()]);
        }
    }

    // ---- people -------------------------------------------------------------------------------

    public static function isStaff(object $user): bool
    {
        return (method_exists($user, 'isAdmin') && $user->isAdmin()) || in_array($user->public_id, self::settings()['support_users'], true)
            || (($user->type ?? null) === 'admin');
    }

    public static function supportUsers()
    {
        return DB::table('users')->whereIn('public_id', self::settings()['support_users'])->whereNull('deleted_at')->get();
    }

    private static function userRow(string $uuid): ?object
    {
        return DB::table('users')->where('uuid', $uuid)->whereNull('deleted_at')->first(['uuid', 'public_id', 'name', 'email', 'type', 'company_uuid']);
    }

    private static function display(?object $u): string
    {
        return $u ? (trim((string) $u->name) ?: Str::before((string) $u->email, '@')) : 'Someone';
    }

    /** People this user may start a chat with: My Network (both ways) + accounts a quote was shared with/by. */
    public static function contacts(object $user): array
    {
        $out = [];
        if (Schema::hasTable('travo_referrals')) {
            foreach (DB::table('travo_referrals')->where('referrer_uuid', $user->uuid)->orWhere('referred_uuid', $user->uuid)->get() as $r) {
                $other = $r->referrer_uuid === $user->uuid ? $r->referred_uuid : $r->referrer_uuid;
                $out[$other] = 'In your network';
            }
        }
        foreach (self::quoteLinks($user) as $uuid => $n) {
            $out[$uuid] = $out[$uuid] ?? ('Shared ' . $n . ' quote' . ($n === 1 ? '' : 's'));
        }
        unset($out[$user->uuid]);
        $rows = DB::table('users')->whereIn('uuid', array_keys($out))->whereNull('deleted_at')->get(['uuid', 'public_id', 'name', 'email']);
        $list = [];
        foreach ($rows as $u) {
            $list[] = ['id' => $u->public_id, 'name' => self::display($u), 'why' => $out[$u->uuid]];
        }
        usort($list, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $list;
    }

    /** uuid => number of quotes shared between $user and that account (either direction). */
    private static function quoteLinks(object $user): array
    {
        $links = [];
        // quotes I shared: recipients' emails in my orders' meta
        $mine = Order::whereNull('deleted_at')->where(function ($q) use ($user) {
            $q->where('created_by_uuid', $user->uuid)->orWhere('customer_uuid', $user->uuid);
        })->where('meta', 'like', '%Shared With Details%')->orderByDesc('created_at')->limit(200)->get(['uuid', 'meta']);
        $emails = [];
        foreach ($mine as $o) {
            foreach (self::sharedEmails($o->meta) as $e) {
                $emails[$e] = ($emails[$e] ?? 0) + 1;
            }
        }
        if ($emails) {
            foreach (DB::table('users')->whereIn(DB::raw('LOWER(email)'), array_keys($emails))->whereNull('deleted_at')->get(['uuid', 'email']) as $u) {
                $links[$u->uuid] = ($links[$u->uuid] ?? 0) + $emails[strtolower($u->email)];
            }
        }
        // quotes shared with me
        if ($user->email) {
            $theirs = Order::whereNull('deleted_at')->where('meta', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $user->email) . '%')
                ->orderByDesc('created_at')->limit(200)->get(['uuid', 'meta', 'created_by_uuid', 'customer_uuid']);
            foreach ($theirs as $o) {
                if (in_array(strtolower($user->email), self::sharedEmails($o->meta), true)) {
                    $by = $o->created_by_uuid ?: $o->customer_uuid;
                    if ($by) {
                        $links[$by] = ($links[$by] ?? 0) + 1;
                    }
                }
            }
        }

        return $links;
    }

    private static function sharedEmails($meta): array
    {
        $meta = is_array($meta) ? $meta : (json_decode((string) $meta, true) ?: []);
        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', (string) data_get($meta, 'Shared With Details', ''), $m);

        return array_values(array_unique(array_map('strtolower', $m[0] ?? [])));
    }

    // ---- channels -----------------------------------------------------------------------------

    private static function meta($c): array
    {
        $m = is_array($c->meta ?? null) ? $c->meta : json_decode((string) ($c->meta ?? ''), true);

        return is_array($m) ? $m : [];
    }

    public static function kind(object $c): string
    {
        $m = self::meta($c);
        if (($m['travo'] ?? null) === 'direct') {
            return 'direct';
        }

        return 'support';    // Travo support, and every older "Untitled Chat" (they were all support requests)
    }

    private static function participant(object $c, object $user, bool $create = false): ?object
    {
        $p = DB::table('chat_participants')->where('chat_channel_uuid', $c->uuid)->where('user_uuid', $user->uuid)->whereNull('deleted_at')->first();
        if (!$p && $create) {
            $uuid = (string) Str::uuid();
            DB::table('chat_participants')->insert([
                'uuid' => $uuid, 'public_id' => 'participant_' . Str::random(7), 'company_uuid' => $c->company_uuid,
                'user_uuid' => $user->uuid, 'chat_channel_uuid' => $c->uuid, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $p = DB::table('chat_participants')->where('uuid', $uuid)->first();
        }

        return $p;
    }

    /** The channel if $user may see it (participant, or staff for support chats). */
    public static function channelFor(object $user, string $id): ?object
    {
        $c = DB::table('chat_channels')->where(function ($q) use ($id) { $q->where('public_id', $id)->orWhere('uuid', $id); })->whereNull('deleted_at')->first();
        if (!$c) {
            return null;
        }
        if (self::participant($c, $user)) {
            return $c;
        }

        return (self::isStaff($user) && self::kind($c) === 'support') ? $c : null;
    }

    private static function newChannel(object $owner, string $name, array $meta): object
    {
        $uuid = (string) Str::uuid();
        DB::table('chat_channels')->insert([
            'uuid' => $uuid, 'public_id' => 'channel_' . Str::random(7), 'company_uuid' => $owner->company_uuid,
            'created_by_uuid' => $owner->uuid, 'name' => Str::limit($name, 180, ''), 'slug' => Str::slug(Str::limit($name, 60, '')) . '-' . Str::lower(Str::random(5)),
            'meta' => json_encode($meta), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('chat_channels')->where('uuid', $uuid)->first();
    }

    /** The customer's support conversation (made on first use), with the support login(s) in it. */
    public static function supportChannel(object $user): object
    {
        $c = DB::table('chat_channels as c')->join('chat_participants as p', 'p.chat_channel_uuid', '=', 'c.uuid')
            ->where('p.user_uuid', $user->uuid)->whereNull('p.deleted_at')->whereNull('c.deleted_at')
            ->where('c.meta', 'like', '%"travo":"support"%')->orderByDesc('c.updated_at')->select('c.*')->first();
        if (!$c) {
            // adopt the customer's latest older "Untitled Chat" that has messages, so their earlier messages are kept
            $old = DB::table('chat_channels as c')->join('chat_participants as p', 'p.chat_channel_uuid', '=', 'c.uuid')
                ->where('p.user_uuid', $user->uuid)->whereNull('p.deleted_at')->whereNull('c.deleted_at')->whereNull('c.meta')
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('chat_messages as m')->whereColumn('m.chat_channel_uuid', 'c.uuid')->whereNull('m.deleted_at'))
                ->orderByDesc('c.updated_at')->select('c.*')->first();
            if ($old) {
                DB::table('chat_channels')->where('uuid', $old->uuid)->update([
                    'name' => Str::limit('Travo Support · ' . self::display($user), 180, ''),
                    'meta' => json_encode(['travo' => 'support', 'customer' => $user->uuid]),
                ]);
                $c = DB::table('chat_channels')->where('uuid', $old->uuid)->first();
            } else {
                $c = self::newChannel($user, 'Travo Support · ' . self::display($user), ['travo' => 'support', 'customer' => $user->uuid]);
                self::participant($c, $user, true);
            }
        }
        foreach (self::supportUsers() as $s) {
            if ($s->uuid !== $user->uuid) {
                self::participant($c, $s, true);
            }
        }

        return $c;
    }

    public static function canDirect(object $user, object $other): bool
    {
        if ($user->uuid === $other->uuid) {
            return false;
        }
        if (self::isStaff($user)) {
            return true;
        }
        foreach (self::contacts($user) as $c) {
            if ($c['id'] === $other->public_id) {
                return true;
            }
        }

        return false;
    }

    public static function directChannel(object $user, object $other): object
    {
        $pair = collect([$user->uuid, $other->uuid])->sort()->implode('|');
        $c    = DB::table('chat_channels')->whereNull('deleted_at')->where('meta', 'like', '%' . $pair . '%')->first();
        if (!$c) {
            $c = self::newChannel($user, self::display($user) . ' & ' . self::display($other), ['travo' => 'direct', 'pair' => $pair]);
        }
        self::participant($c, $user, true);
        self::participant($c, $other, true);

        return $c;
    }

    /** Title, people and avatar letter of a channel, as $viewer sees it. */
    private static function describe(object $c, object $viewer, array &$users): array
    {
        $kind  = self::kind($c);
        $parts = DB::table('chat_participants')->where('chat_channel_uuid', $c->uuid)->whereNull('deleted_at')->pluck('user_uuid')->all();
        foreach ($parts as $u) {
            if (!array_key_exists($u, $users)) {
                $users[$u] = self::userRow($u);
            }
        }
        $staff = self::isStaff($viewer);
        if ($kind === 'support') {
            if ($staff) {
                $cust  = self::meta($c)['customer'] ?? $c->created_by_uuid;
                $users[$cust] = $users[$cust] ?? self::userRow($cust);
                $title = self::display($users[$cust]) . ' · support';
                $sub   = optional($users[$cust])->email;
            } else {
                $title = self::settings()['support_name'];
                $sub   = 'We usually reply within minutes, 8am–8pm';
            }
        } else {
            $other = collect($parts)->first(fn ($u) => $u !== $viewer->uuid);
            $title = self::display($users[$other] ?? null);
            $sub   = 'Direct chat';
        }

        return ['kind' => $kind, 'title' => $title, 'sub' => $sub];
    }

    /** The chat list: conversations (newest first), contacts, unread counts. */
    public static function overview(object $user): array
    {
        $staff = self::isStaff($user);
        $q     = DB::table('chat_channels as c')->whereNull('c.deleted_at');
        if ($staff) {
            $q->where(function ($w) use ($user) {
                $w->whereIn('c.uuid', DB::table('chat_participants')->where('user_uuid', $user->uuid)->whereNull('deleted_at')->select('chat_channel_uuid'))
                  ->orWhere('c.meta', 'like', '%"travo":"support"%')
                  ->orWhereNull('c.meta');      // older "Untitled Chat"s: all were support requests (never direct)
            });
        } else {
            $q->whereIn('c.uuid', DB::table('chat_participants')->where('user_uuid', $user->uuid)->whereNull('deleted_at')->select('chat_channel_uuid'));
        }
        $chans = $q->orderByDesc('c.updated_at')->limit($staff ? 150 : 60)->get();

        $uuids = $chans->pluck('uuid')->all();
        $last  = DB::table('chat_messages')->whereIn('chat_channel_uuid', $uuids)->whereNull('deleted_at')
            ->whereIn('id', DB::table('chat_messages')->selectRaw('MAX(id)')->whereIn('chat_channel_uuid', $uuids)->whereNull('deleted_at')->groupBy('chat_channel_uuid'))
            ->get()->keyBy('chat_channel_uuid');
        $reads = DB::table(self::READS)->where('user_uuid', $user->uuid)->whereIn('channel_uuid', $uuids)->pluck('read_at', 'channel_uuid');
        $myParts = DB::table('chat_participants')->where('user_uuid', $user->uuid)->whereIn('chat_channel_uuid', $uuids)->pluck('uuid')->all();

        $users = [];
        $list  = [];
        $total = 0;
        foreach ($chans as $c) {
            $m = $last->get($c->uuid);
            if (!$m && self::kind($c) === 'support' && !self::participant($c, $user)) {
                continue;   // staff: empty support chats aren't worth listing
            }
            if (!$m && self::kind($c) === 'support' && !$staff && $c->name === 'Untitled Chat') {
                continue;   // old empty "Untitled Chat"s
            }
            $d      = self::describe($c, $user, $users);
            $unread = 0;
            if ($m) {
                $since  = $reads[$c->uuid] ?? null;
                $unread = DB::table('chat_messages')->where('chat_channel_uuid', $c->uuid)->whereNull('deleted_at')
                    ->whereNotIn('sender_uuid', $myParts ?: ['-'])
                    ->when($since, fn ($w) => $w->where('created_at', '>', $since))->count();
                $unread = min($unread, 99);
            }
            $total += $unread;
            $list[] = $d + [
                'id'     => $c->public_id,
                'last'   => $m ? self::preview((string) $m->content) : '',
                'at'     => $m ? Carbon::parse($m->created_at)->toIso8601String() : Carbon::parse($c->created_at)->toIso8601String(),
                'unread' => $unread,
            ];
        }
        usort($list, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return [
            'me'       => ['id' => $user->public_id, 'name' => self::display($user), 'staff' => $staff],
            'channels' => $list,
            'contacts' => $staff ? [] : self::contacts($user),
            'unread'   => $total,
            'support'  => self::settings()['support_name'],
        ];
    }

    public static function unread(object $user): int
    {
        return self::overview($user)['unread'];
    }

    private static function preview(string $content): string
    {
        $content = preg_replace('/\[\[quote:[A-Za-z0-9_]+\]\]/', '📄 Quote', $content);

        return Str::limit(trim(preg_replace('/\s+/', ' ', $content)), 80);
    }

    // ---- messages -----------------------------------------------------------------------------

    public static function messages(object $user, object $c, ?string $after = null): array
    {
        $users = [];
        $d     = self::describe($c, $user, $users);
        $q     = DB::table('chat_messages')->where('chat_channel_uuid', $c->uuid)->whereNull('deleted_at');
        if ($after) {
            $q->where('id', '>', (int) $after);
        }
        $rows = $after ? $q->orderBy('id')->limit(200)->get() : $q->orderByDesc('id')->limit(120)->get()->reverse()->values();

        $parts = DB::table('chat_participants')->whereIn('uuid', $rows->pluck('sender_uuid')->unique())->pluck('user_uuid', 'uuid');
        $atts  = DB::table('chat_attachments as a')->join('files as f', 'f.uuid', '=', 'a.file_uuid')
            ->whereIn('a.chat_message_uuid', $rows->pluck('uuid'))->whereNull('a.deleted_at')
            ->get(['a.chat_message_uuid', 'f.uuid', 'f.original_filename', 'f.content_type', 'f.file_size'])->groupBy('chat_message_uuid');
        $staffUuids = self::supportUsers()->pluck('uuid')->all();

        $out = [];
        foreach ($rows as $m) {
            $uu = $parts[$m->sender_uuid] ?? $m->sender_uuid;
            if (!array_key_exists($uu, $users)) {
                $users[$uu] = self::userRow($uu);
            }
            $sender = $users[$uu] ?? null;
            $isStaffMsg = $sender && (in_array($sender->uuid, $staffUuids, true) || ($sender->type ?? '') === 'admin');
            $text   = (string) $m->content;
            $quotes = [];
            if (preg_match_all('/\[\[quote:([A-Za-z0-9_]+)\]\]/', $text, $qm)) {
                foreach (array_unique($qm[1]) as $pid) {
                    if ($card = self::quoteCard($pid)) {
                        $quotes[] = $card;
                    }
                }
                $text = trim(preg_replace('/\[\[quote:[A-Za-z0-9_]+\]\]/', '', $text));
            }
            $out[] = [
                'id'     => $m->id,
                'mine'   => $sender && $sender->uuid === $user->uuid,
                'from'   => ($isStaffMsg && self::kind($c) === 'support' && !self::isStaff($user)) ? self::settings()['support_name'] : self::display($sender),
                'staff'  => $isStaffMsg,
                'text'   => $text,
                'quotes' => $quotes,
                'files'  => collect($atts->get($m->uuid) ?? [])->map(fn ($f) => [
                    'name'  => $f->original_filename ?: 'file',
                    'type'  => (string) $f->content_type,
                    'size'  => (int) $f->file_size,
                    'url'   => self::fileUrl($f->uuid),
                    'image' => str_starts_with((string) $f->content_type, 'image/'),
                ])->values()->all(),
                'at'     => Carbon::parse($m->created_at)->toIso8601String(),
            ];
        }
        DB::table(self::READS)->updateOrInsert(['channel_uuid' => $c->uuid, 'user_uuid' => $user->uuid], ['read_at' => now(), 'updated_at' => now(), 'created_at' => now()]);

        return $d + [
            'id'       => $c->public_id,
            'messages' => $out,
            'shared'   => (!$after && $d['kind'] === 'direct') ? self::sharedQuotes($c, $user) : [],
            'myQuotes' => $after ? [] : self::myQuotes($user),
        ];
    }

    public static function send(object $user, object $c, string $text, ?string $quote = null): array
    {
        $text = trim(Str::limit($text, 4000, ''));
        if ($quote && preg_match('/^[A-Za-z0-9_]+$/', $quote) && self::canShowQuote($user, $quote)) {
            $text = trim($text . "\n[[quote:" . $quote . ']]');
        }
        if ($text === '') {
            return ['error' => 'Type a message first.'];
        }
        $p = self::participant($c, $user, true);   // staff replying to a support chat join it
        $uuid = (string) Str::uuid();
        DB::table('chat_messages')->insert([
            'uuid' => $uuid, 'public_id' => 'message_' . Str::random(7), 'company_uuid' => $c->company_uuid,
            'sender_uuid' => $p->uuid, 'chat_channel_uuid' => $c->uuid, 'content' => $text, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('chat_channels')->where('uuid', $c->uuid)->update(['updated_at' => now()]);
        DB::table(self::READS)->updateOrInsert(['channel_uuid' => $c->uuid, 'user_uuid' => $user->uuid], ['read_at' => now(), 'updated_at' => now(), 'created_at' => now()]);

        return ['ok' => true, 'uuid' => $uuid];
    }

    public static function attach(object $user, object $c, UploadedFile $file, string $caption = ''): array
    {
        $s   = self::settings();
        $ext = Str::lower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');
        if (!in_array($ext, self::FILE_TYPES, true)) {
            return ['error' => 'That file type can\'t be sent. Photos, PDFs, Word, Excel and zip files are fine.'];
        }
        if ($file->getSize() > $s['max_file_mb'] * 1024 * 1024) {
            return ['error' => 'Files can be up to ' . $s['max_file_mb'] . ' MB.'];
        }
        $name = Str::limit(preg_replace('/[^\w .()\-]/u', '_', $file->getClientOriginalName() ?: ('file.' . $ext)), 120, '');
        $fuid = (string) Str::uuid();
        $path = 'travo-chat/' . now()->format('Y/m') . '/' . $fuid . '.' . $ext;
        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $sent = self::send($user, $c, trim($caption) !== '' ? $caption : '📎 ' . $name);
        $msg  = DB::table('chat_messages')->where('uuid', $sent['uuid'])->first();
        DB::table('files')->insert([
            'uuid' => $fuid, 'public_id' => 'file_' . Str::random(7), 'company_uuid' => $c->company_uuid, 'uploader_uuid' => $user->uuid,
            'subject_uuid' => $msg->uuid, 'subject_type' => 'chat_message', 'disk' => 'local', 'path' => $path, 'folder' => 'travo-chat',
            'original_filename' => $name, 'type' => 'chat_attachment', 'content_type' => $file->getMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('chat_attachments')->insert([
            'uuid' => (string) Str::uuid(), 'public_id' => 'attachment_' . Str::random(7), 'company_uuid' => $c->company_uuid,
            'sender_uuid' => $msg->sender_uuid, 'chat_channel_uuid' => $c->uuid, 'chat_message_uuid' => $msg->uuid, 'file_uuid' => $fuid,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['ok' => true];
    }

    public static function fileSig(string $uuid): string
    {
        return substr(hash_hmac('sha256', 'chat-file|' . $uuid, (string) config('app.key')), 0, 32);
    }

    public static function fileUrl(string $uuid): string
    {
        return 'https://travo.ng/chat-file/' . $uuid . '/' . self::fileSig($uuid);
    }

    // ---- quotes in chat ---------------------------------------------------------------------

    private static function canShowQuote(object $user, string $pid): bool
    {
        $o = Order::where('public_id', $pid)->whereNull('deleted_at')->first(['uuid', 'company_uuid', 'created_by_uuid', 'customer_uuid', 'meta']);
        if (!$o) {
            return false;
        }

        return self::isStaff($user) || $o->created_by_uuid === $user->uuid || $o->customer_uuid === $user->uuid
            || ($user->company_uuid && $o->company_uuid === $user->company_uuid)
            || ($user->email && in_array(strtolower($user->email), self::sharedEmails($o->meta), true));
    }

    /** Public, privacy-safe summary of a quote (same as the shared quote page: areas only, no names). */
    public static function quoteCard(string $pid): ?array
    {
        $o = Order::with(['payload.pickup', 'payload.dropoff', 'payload.entities', 'purchaseRate.serviceQuote.items'])->where('public_id', $pid)->first();
        if (!$o) {
            return null;
        }

        return rescue(function () use ($o) {
            $q = app(QuoteShareController::class)->buildQuote($o, QuoteShareController::code($o->public_id));

            return [
                'ref'   => $o->public_id,
                'type'  => $q['orderType'],
                'price' => $q['price'],
                'route' => trim(($q['pickup']['label'] ?? '') . (!empty($q['dropoff']) ? ' → ' . $q['dropoff']['label'] : '')),
                'date'  => $q['created'],
                'status' => $q['status'],
                'url'   => $q['url'],
            ];
        }, null, false);
    }

    /** Recent orders the user can attach to a message. */
    private static function myQuotes(object $user): array
    {
        $orders = Order::whereNull('deleted_at')->where(function ($q) use ($user) {
            $q->where('created_by_uuid', $user->uuid)->orWhere('customer_uuid', $user->uuid);
        })->orderByDesc('created_at')->limit(8)->get(['public_id', 'type', 'created_at']);

        return $orders->map(fn ($o) => ['ref' => $o->public_id, 'label' => Str::headline(Str::before((string) $o->type, '-')) . ' · ' . optional($o->created_at)->format('j M')])->all();
    }

    /** Quotes the two people in a direct chat have shared with each other. */
    private static function sharedQuotes(object $c, object $viewer): array
    {
        $parts = DB::table('chat_participants')->where('chat_channel_uuid', $c->uuid)->whereNull('deleted_at')->pluck('user_uuid')->all();
        $users = DB::table('users')->whereIn('uuid', $parts)->get(['uuid', 'email'])->keyBy('uuid');
        $cards = [];
        foreach ($users as $a) {
            foreach ($users as $b) {
                if ($a->uuid === $b->uuid || !$b->email) {
                    continue;
                }
                $orders = Order::whereNull('deleted_at')->where(function ($q) use ($a) {
                    $q->where('created_by_uuid', $a->uuid)->orWhere('customer_uuid', $a->uuid);
                })->where('meta', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $b->email) . '%')->orderByDesc('created_at')->limit(6)->get(['public_id', 'meta']);
                foreach ($orders as $o) {
                    if (in_array(strtolower($b->email), self::sharedEmails($o->meta), true) && count($cards) < 6 && ($card = self::quoteCard($o->public_id))) {
                        $card['by'] = $a->uuid === $viewer->uuid ? 'You shared' : 'Shared with you';
                        $cards[$o->public_id] = $card;
                    }
                }
            }
        }

        return array_values($cards);
    }

    // ---- staff email when a support message waits ---------------------------------------------

    public static function alertWaiting(): int
    {
        $s = self::settings();
        if (!$s['alert_email']) {
            return 0;
        }
        $staffParts = DB::table('chat_participants')->whereIn('user_uuid', self::supportUsers()->pluck('uuid')->merge(DB::table('users')->where('type', 'admin')->pluck('uuid')))->pluck('uuid')->all();
        $since = now()->subDays(2);
        $chans = DB::table('chat_channels')->whereNull('deleted_at')->where('updated_at', '>=', $since)->get();
        $n     = 0;
        foreach ($chans as $c) {
            if (self::kind($c) !== 'support') {
                continue;
            }
            $last = DB::table('chat_messages')->where('chat_channel_uuid', $c->uuid)->whereNull('deleted_at')->orderByDesc('id')->first();
            if (!$last || in_array($last->sender_uuid, $staffParts, true)) {
                continue;   // nothing yet, or staff spoke last
            }
            if (Carbon::parse($last->created_at)->gt(now()->subMinutes((int) $s['alert_after_min']))) {
                continue;   // give staff a few minutes first
            }
            $meta = self::meta($c);
            $prev = isset($meta['alerted_at']) ? Carbon::parse($meta['alerted_at']) : null;
            if ($prev && ($prev->gt(Carbon::parse($last->created_at)) || $prev->gt(now()->subMinutes((int) $s['alert_every_min'])))) {
                continue;
            }
            $sender = DB::table('chat_participants')->where('uuid', $last->sender_uuid)->value('user_uuid');
            $u      = $sender ? self::userRow($sender) : null;
            $waiting = DB::table('chat_messages')->where('chat_channel_uuid', $c->uuid)->whereNull('deleted_at')
                ->where('id', '>', (int) (DB::table('chat_messages')->where('chat_channel_uuid', $c->uuid)->whereIn('sender_uuid', $staffParts ?: ['-'])->max('id') ?? 0))
                ->orderBy('id')->limit(10)->pluck('content')->map(fn ($t) => '> ' . self::preview((string) $t))->implode("\n");
            $ok = rescue(function () use ($s, $u, $waiting, $c) {
                Mail::raw(
                    self::display($u) . ' (' . optional($u)->email . ") is waiting for a reply in Travo chat:\n\n" . $waiting
                    . "\n\nReply in the console: https://travo.ng/#chat (log in with the support login, open Chat).",
                    function ($m) use ($s, $u) {
                        $m->to($s['alert_email'])->subject('[Travo chat] ' . self::display($u) . ' is waiting for a reply');
                        if ($u && $u->email) {
                            $m->replyTo($u->email, self::display($u));
                        }
                    }
                );

                return true;
            }, false, true);
            if ($ok) {
                $meta['alerted_at'] = now()->toIso8601String();
                DB::table('chat_channels')->where('uuid', $c->uuid)->update(['meta' => json_encode($meta)]);   // keeps updated_at order
                $n++;
            }
        }

        return $n;
    }

    // ---- customer emails: "you have a new message" --------------------------------------------

    public static function emailOffSig(string $userUuid): string
    {
        return substr(hash_hmac('sha256', 'chat-email-off|' . $userUuid, (string) config('app.key')), 0, 32);
    }

    public static function emailOffUrl(string $userUuid): string
    {
        return 'https://travo.ng/unsubscribe/chat/' . $userUuid . '/' . self::emailOffSig($userUuid);
    }

    /**
     * Email people (not staff) about messages they haven't read, once per conversation per notify_hours:
     * the first unread message of a new discussion gets an email with a preview; later messages in the same
     * 24 hours don't. Nobody is emailed if they read it in the app within notify_delay_min, opted out, or
     * their address bounced. Runs from travo:chat --run (every 5 minutes).
     */
    public static function notifyRecipients(bool $dry = false): array
    {
        $s   = self::settings();
        $out = ['sent' => 0, 'skipped' => 0, 'would' => []];
        if (!$s['notify_email'] || !$s['notify_since'] || !Schema::hasTable(self::EMAILS)) {
            return $out;
        }
        $from = Carbon::parse($s['notify_since'])->max(now()->subHours(26));
        $msgs = DB::table('chat_messages')->whereNull('deleted_at')->where('created_at', '>=', $from)
            ->where('created_at', '<=', now()->subMinutes((int) $s['notify_delay_min']))->orderBy('id')->limit(2000)->get();
        if ($msgs->isEmpty()) {
            return $out;
        }
        $staff     = self::supportUsers()->pluck('uuid')->merge(DB::table('users')->where('type', 'admin')->pluck('uuid'))->unique()->all();
        $chans     = DB::table('chat_channels')->whereIn('uuid', $msgs->pluck('chat_channel_uuid')->unique())->whereNull('deleted_at')->get()->keyBy('uuid');
        $parts     = DB::table('chat_participants')->whereIn('chat_channel_uuid', $chans->keys())->whereNull('deleted_at')->get();
        $partUser  = $parts->pluck('user_uuid', 'uuid');
        $off       = DB::table(self::EMAIL_OFF)->pluck('user_uuid')->flip();
        $bounced   = Schema::hasTable(TravoDrip::TABLE) ? DB::table(TravoDrip::TABLE)->where('status', 'bounced')->pluck('email')->map(fn ($e) => strtolower($e))->flip() : collect();
        $done      = [];   // channel|user handled in this run

        foreach ($msgs as $m) {
            $c = $chans[$m->chat_channel_uuid] ?? null;
            if (!$c) {
                continue;
            }
            $senderUser = $partUser[$m->sender_uuid] ?? $m->sender_uuid;
            $kind       = self::kind($c);
            $meta       = self::meta($c);
            // who should hear about it: support chats -> the customer; direct chats -> everyone else in it
            $to = $kind === 'support'
                ? array_filter([$meta['customer'] ?? null])
                : $parts->where('chat_channel_uuid', $c->uuid)->pluck('user_uuid')->unique()->values()->all();
            foreach ($to as $uu) {
                $key = $c->uuid . '|' . $uu;
                if ($uu === $senderUser || in_array($uu, $staff, true) || isset($done[$key]) || isset($off[$uu])) {
                    continue;
                }
                $done[$key] = true;
                $read = DB::table(self::READS)->where('channel_uuid', $c->uuid)->where('user_uuid', $uu)->value('read_at');
                if ($read && Carbon::parse($read)->gte(Carbon::parse($m->created_at))) {
                    continue;   // already seen in the app
                }
                $recent = DB::table(self::EMAILS)->where('channel_uuid', $c->uuid)->where('user_uuid', $uu)
                    ->where('emailed_at', '>=', now()->subHours((int) $s['notify_hours']))->exists();
                $already = DB::table(self::EMAILS)->where('channel_uuid', $c->uuid)->where('user_uuid', $uu)->where('message_id', '>=', $m->id)->exists();
                if ($recent || $already) {
                    continue;
                }
                $u = self::userRow($uu);
                if (!$u || !filter_var((string) $u->email, FILTER_VALIDATE_EMAIL) || isset($bounced[strtolower($u->email)])) {
                    $out['skipped']++;
                    continue;
                }
                $sender   = self::userRow($senderUser);
                $fromName = ($kind === 'support' && in_array($senderUser, $staff, true)) ? $s['support_name'] : self::display($sender);
                $text     = trim(preg_replace('/\[\[quote:[A-Za-z0-9_]+\]\]/', '', (string) $m->content));
                $hasQuote = (bool) preg_match('/\[\[quote:/', (string) $m->content);
                $preview  = Str::limit(trim(preg_replace('/\s+/', ' ', $text)), 220) ?: ($hasQuote ? 'Sent you a quote.' : 'Sent you a message.');
                $more     = DB::table('chat_messages')->where('chat_channel_uuid', $c->uuid)->whereNull('deleted_at')->where('id', '>', $m->id)
                    ->where('sender_uuid', $m->sender_uuid)->count();
                if ($dry) {
                    $out['would'][] = ['to' => $u->email, 'from' => $fromName, 'preview' => Str::limit($preview, 60)];
                    continue;
                }
                $data = [
                    'subject'  => $fromName . ' sent you a message on Travo',
                    'name'     => trim(explode(' ', trim((string) $u->name))[0] ?? ''),
                    'fromName' => $fromName,
                    'preview'  => $preview,
                    'more'     => $more,
                    'hasQuote' => $hasQuote,
                    'openUrl'  => 'https://travo.ng/#chat',
                    'offUrl'   => self::emailOffUrl($uu),
                    'hours'    => (int) $s['notify_hours'],
                ];
                $ok = rescue(function () use ($data, $u) {
                    Mail::send('emails.travo.chat-message', $data, function ($mm) use ($data, $u) {
                        $mm->to($u->email, self::display($u))->subject($data['subject']);
                        $mm->replyTo('hello@travoservices.com', 'Travo');
                        if ($rp = TravoBounce::returnPath()) {
                            $mm->returnPath($rp);
                        }
                        $h = $mm->getSymfonyMessage()->getHeaders();
                        $h->addTextHeader('List-Unsubscribe', '<' . $data['offUrl'] . '>');
                        $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                    });

                    return true;
                }, false, true);
                if ($ok) {
                    DB::table(self::EMAILS)->insert(['channel_uuid' => $c->uuid, 'user_uuid' => $uu, 'message_id' => $m->id, 'email' => $u->email, 'emailed_at' => now()]);
                    $out['sent']++;
                } else {
                    $out['skipped']++;
                }
            }
        }

        return $out;
    }

    public static function emailOff(string $userUuid): void
    {
        DB::table(self::EMAIL_OFF)->insertOrIgnore(['user_uuid' => $userUuid, 'created_at' => now()]);
    }
}
