<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Travo email calendar: bounce handling (26 Oct 2026).
 *
 * Calendar emails are sent from the site's mailbox (hello@travoservices.com on IONOS), so "undeliverable"
 * reports come back to that inbox. Each hourly run logs in to the same mailbox over IMAP with the SMTP
 * login the site already uses (config mail.mailers.smtp; nothing extra is stored), looks ONLY at
 * delivery-failure reports (from mailer-daemon / postmaster, or "Undeliverable / Delivery Status /
 * failure" subjects) received since the calendar started, reads them without marking them as read, and
 * stops the calendar for each permanently failed address (status "bounced"). It never changes, moves or
 * deletes anything in the mailbox; the last processed message number is kept in the calendar settings.
 *
 * Optional overrides in the API .env: TRAVO_BOUNCE_IMAP_HOST / _PORT / _USER / _PASS, TRAVO_BOUNCE_ADDRESS
 * (a separate Return-Path mailbox).
 * Also stops My Network invites to bounced addresses (27 Oct 2026). Default host: settings "bounce_imap_host" (imap.ionos.co.uk).
 */
class TravoBounce
{
    private static function creds(): array
    {
        $s = TravoDrip::settings();

        return [
            'host' => env('TRAVO_BOUNCE_IMAP_HOST') ?: ($s['bounce_imap_host'] ?? null),
            'port' => (int) (env('TRAVO_BOUNCE_IMAP_PORT') ?: 993),
            'user' => env('TRAVO_BOUNCE_IMAP_USER') ?: config('mail.mailers.smtp.username'),
            'pass' => env('TRAVO_BOUNCE_IMAP_PASS') ?: config('mail.mailers.smtp.password'),
        ];
    }

    public static function configured(): bool
    {
        $c = self::creds();

        return (bool) ($c['host'] && $c['user'] && $c['pass']);
    }

    /** Only when a separate bounce mailbox is set; otherwise bounces go to the sender (hello@) as now. */
    public static function returnPath(): ?string
    {
        $a = env('TRAVO_BOUNCE_ADDRESS');

        return $a && filter_var($a, FILTER_VALIDATE_EMAIL) ? $a : null;
    }

    /** Reads new delivery-failure reports and marks bounced addresses. Returns a summary (no secrets). */
    public static function check(int $max = 200): array
    {
        $out = ['configured' => self::configured(), 'read' => 0, 'bounced' => [], 'error' => null];
        if (!$out['configured']) {
            return $out;
        }
        $c     = self::creds();
        $s     = TravoDrip::settings();
        $since = $s['started_at'] ? date('j-M-Y', strtotime($s['started_at']) - 86400) : date('j-M-Y', time() - 86400);
        $last  = (int) ($s['bounce_last_uid'] ?? 0);
        $imap  = null;
        try {
            $imap = new ImapLite($c['host'], $c['port']);
            $imap->login($c['user'], $c['pass']);
            $imap->examine('INBOX');   // read-only
            $uids = $imap->uidSearch('SINCE ' . $since . ' OR OR OR FROM "mailer-daemon" FROM "postmaster" SUBJECT "Undeliver" OR SUBJECT "Delivery Status" SUBJECT "failure"');
            $uids = array_values(array_filter($uids, fn ($u) => $u > $last));
            sort($uids);
            $uids = array_slice($uids, 0, $max);
            foreach ($uids as $uid) {
                $raw = $imap->uidFetch($uid);
                $out['read']++;
                foreach (self::failedRecipients($raw) as $email) {
                    if (self::markBounced($email, $raw)) {
                        $out['bounced'][] = $email;
                    }
                }
                $last = max($last, $uid);
            }
            $imap->logout();
        } catch (\Throwable $e) {
            $out['error'] = preg_replace('/LOGIN .*/', 'LOGIN …', $e->getMessage());
            Log::warning('Travo bounce check failed: ' . $out['error']);
        }
        if ($last !== (int) ($s['bounce_last_uid'] ?? 0)) {
            $s2                    = TravoDrip::settings();
            $s2['bounce_last_uid'] = $last;
            TravoDrip::saveSettings($s2);
        }
        $out['bounced'] = array_values(array_unique($out['bounced']));

        return $out;
    }

    /** Permanent failures only (DSN "Action: failed" with 5.x.x status, or classic "user unknown" text). */
    public static function failedRecipients(string $raw): array
    {
        $found = [];
        if (preg_match_all('/Final-Recipient:\s*rfc822;\s*<?([^\s>]+@[^\s>]+)>?.*?Action:\s*(\w+)(?:.*?Status:\s*([0-9.]+))?/is', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if (strtolower($x[2]) === 'failed' && (!isset($x[3]) || $x[3] === '' || str_starts_with($x[3], '5'))) {
                    $found[] = strtolower(trim($x[1], " <>\r\n\t;"));
                }
            }
        }
        if (!$found && preg_match('/(user unknown|does not exist|no such user|mailbox unavailable|address rejected|recipient address rejected|invalid recipient|mailbox not found|550[ -]5\.1\.1)/i', $raw)) {
            if (preg_match_all('/<?([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})>?/', $raw, $mm)) {
                foreach (array_unique(array_map('strtolower', $mm[1])) as $e) {
                    if (!str_ends_with($e, '@travoservices.com') && !str_contains($e, 'ionos') && !str_contains($e, 'mailer-daemon') && !str_contains($e, 'postmaster')
                        && (DB::table(TravoDrip::TABLE)->where('email', $e)->exists() || self::invited($e))) {
                        $found[] = $e;
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** True if a My Network invite went to this address. */
    private static function invited(string $email): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('travo_network_invites')
            && DB::table('travo_network_invites')->where('email', strtolower($email))->whereNotNull('sent_at')->exists();
    }

    public static function markBounced(string $email, string $raw = ''): bool
    {
        if (self::invited($email)) {
            TravoNetwork::suppress($email, 'bounced');   // My Network invites: no reminder, nobody can invite it again
        }
        $row = DB::table(TravoDrip::TABLE)->where('email', strtolower($email))->first();
        if (!$row || in_array($row->status, ['bounced', 'unsubscribed'], true)) {
            return false;
        }
        $code = preg_match('/Status:\s*([0-9.]+)/i', $raw, $m) ? $m[1] : '';
        DB::table(TravoDrip::TABLE)->where('id', $row->id)->update([
            'status' => 'bounced', 'next_at' => null, 'note' => trim('email bounced ' . $code), 'updated_at' => now(),
        ]);

        return true;
    }
}

/** Minimal read-only IMAP client over TLS. */
class ImapLite
{
    private $fp;

    private int $tag = 0;

    public function __construct(string $host, int $port = 993)
    {
        $ctx      = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $this->fp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $err, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->fp) {
            throw new \RuntimeException('IMAP connect failed: ' . $err);
        }
        stream_set_timeout($this->fp, 30);
        fgets($this->fp);
    }

    private function quote(string $s): string
    {
        return '"' . addcslashes($s, "\"\\") . '"';
    }

    private function cmd(string $c, string $label): string
    {
        $t = 'A' . (++$this->tag);
        fwrite($this->fp, $t . ' ' . $c . "\r\n");
        $buf = '';
        while (($line = fgets($this->fp)) !== false) {
            if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                $buf .= $line . stream_get_contents($this->fp, (int) $m[1]);
                continue;
            }
            if (str_starts_with($line, $t . ' ')) {
                if (!preg_match('/^' . $t . ' OK/i', $line)) {
                    throw new \RuntimeException('IMAP ' . $label . ' failed: ' . trim(substr($line, strlen($t) + 1, 120)));
                }

                return $buf;
            }
            $buf .= $line;
        }
        throw new \RuntimeException('IMAP connection closed during ' . $label);
    }

    public function login(string $u, string $p): void
    {
        $this->cmd('LOGIN ' . $this->quote($u) . ' ' . $this->quote($p), 'login');
    }

    public function examine(string $box): void
    {
        $this->cmd('EXAMINE ' . $this->quote($box), 'examine');
    }

    public function uidSearch(string $criteria): array
    {
        $r = $this->cmd('UID SEARCH ' . $criteria, 'search');

        return preg_match('/\* SEARCH ?([0-9 ]*)/', $r, $m) ? array_values(array_filter(array_map('intval', explode(' ', trim($m[1]))))) : [];
    }

    public function uidFetch(int $uid): string
    {
        return substr($this->cmd('UID FETCH ' . $uid . ' BODY.PEEK[]', 'fetch'), 0, 400000);
    }

    public function logout(): void
    {
        try {
            $this->cmd('LOGOUT', 'logout');
        } catch (\Throwable $e) {
        }
        @fclose($this->fp);
    }
}
