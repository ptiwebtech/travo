<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Travo: records the red error notices and failed requests that signed-in console users see,
 * so they can be looked at later (they disappear from the screen after a few seconds).
 *
 *   POST /int/v1/travo/client-errors   {events: [{kind, message, url, status, method, route, at}]}
 *
 * Written to storage/logs/travo-client-errors-YYYY-MM-DD.log, one JSON line per event.
 * Query strings are dropped and messages are trimmed, so no tokens or form data are kept.
 */
class ClientErrorController extends Controller
{
    public function store(Request $request)
    {
        $events = array_slice((array) $request->input('events', []), 0, 25);
        $user   = $request->user();
        $lines  = '';
        foreach ($events as $e) {
            if (!is_array($e)) {
                continue;
            }
            $lines .= json_encode([
                'at'      => now()->toIso8601String(),
                'seen'    => substr((string) ($e['at'] ?? ''), 0, 30),
                'kind'    => substr((string) ($e['kind'] ?? ''), 0, 20),
                'message' => $this->trim($e['message'] ?? ''),
                'method'  => substr(strtoupper((string) ($e['method'] ?? '')), 0, 8),
                'url'     => $this->path($e['url'] ?? ''),
                'status'  => (int) ($e['status'] ?? 0),
                'page'    => $this->path($e['route'] ?? ''),
                'user'    => optional($user)->public_id,
                'company' => optional($user)->company_uuid,
                'ua'      => substr((string) $request->userAgent(), 0, 160),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }
        if ($lines !== '') {
            @file_put_contents(storage_path('logs/travo-client-errors-' . now()->format('Y-m-d') . '.log'), $lines, FILE_APPEND | LOCK_EX);
        }

        return response()->json(['ok' => true]);
    }

    private function path($url): string
    {
        $url = (string) $url;
        $url = preg_replace('/[?#].*$/s', '', $url);

        return substr($url, 0, 200);
    }

    private function trim($text): string
    {
        $text = preg_replace('/\s+/', ' ', strip_tags((string) $text));
        $text = preg_replace('/(Bearer|token|password|secret)\S*\s*\S+/i', '$1 [removed]', $text);

        return substr(trim($text), 0, 300);
    }
}
