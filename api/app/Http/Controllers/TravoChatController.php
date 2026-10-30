<?php

namespace App\Http\Controllers;

use App\Support\TravoChat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Travo chat API for the console add-on (int/v1/travo/chat...) and signed file downloads (/share/chat-file). */
class TravoChatController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(TravoChat::overview($request->user()));
    }

    public function unread(Request $request)
    {
        return response()->json(['unread' => TravoChat::unread($request->user())]);
    }

    public function support(Request $request)
    {
        $c = TravoChat::supportChannel($request->user());

        return response()->json(['id' => $c->public_id]);
    }

    public function direct(Request $request)
    {
        $user  = $request->user();
        $other = DB::table('users')->where('public_id', (string) $request->input('user'))->whereNull('deleted_at')->first();
        if (!$other || !TravoChat::canDirect($user, $other)) {
            return response()->json(['error' => 'You can chat with people in your network, or people you have shared a quote with.'], 403);
        }

        return response()->json(['id' => TravoChat::directChannel($user, $other)->public_id]);
    }

    public function messages(Request $request, string $id)
    {
        $c = TravoChat::channelFor($request->user(), $id);
        if (!$c) {
            return response()->json(['error' => 'Chat not found.'], 404);
        }
        $after = $request->query('after');

        return response()->json(TravoChat::messages($request->user(), $c, is_numeric($after) ? (string) (int) $after : null));
    }

    public function send(Request $request, string $id)
    {
        $c = TravoChat::channelFor($request->user(), $id);
        if (!$c) {
            return response()->json(['error' => 'Chat not found.'], 404);
        }
        $r = TravoChat::send($request->user(), $c, (string) $request->input('text', ''), $request->input('quote') ? (string) $request->input('quote') : null);

        return response()->json($r, isset($r['error']) ? 422 : 200);
    }

    public function upload(Request $request, string $id)
    {
        $c = TravoChat::channelFor($request->user(), $id);
        if (!$c) {
            return response()->json(['error' => 'Chat not found.'], 404);
        }
        $f = $request->file('file');
        if (!$f || !$f->isValid()) {
            return response()->json(['error' => 'The file did not upload. Please try again.'], 422);
        }
        $r = TravoChat::attach($request->user(), $c, $f, (string) $request->input('text', ''));

        return response()->json($r, isset($r['error']) ? 422 : 200);
    }

    /** Signed link: anyone with the link (people in the chat) can open the file. */
    public function file(string $uuid, string $sig)
    {
        if (!hash_equals(TravoChat::fileSig($uuid), $sig)) {
            abort(404);
        }
        $f = DB::table('files')->where('uuid', $uuid)->where('folder', 'travo-chat')->first();
        if (!$f || !Storage::disk('local')->exists($f->path)) {
            abort(404);
        }
        $inline = str_starts_with((string) $f->content_type, 'image/') || $f->content_type === 'application/pdf';

        return response()->file(Storage::disk('local')->path($f->path), [
            'Content-Type'        => $f->content_type,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes($f->original_filename) . '"',
            'Cache-Control'       => 'private, max-age=86400',
            'X-Robots-Tag'        => 'noindex',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Signed link in the "new message" email: stop chat emails for this person (GET shows a page, POST = one-click). */
    public function emailOff(Request $request, string $uuid, string $sig)
    {
        if (!hash_equals(TravoChat::emailOffSig($uuid), $sig)) {
            abort(404);
        }
        TravoChat::emailOff($uuid);
        if ($request->isMethod('post')) {
            return response('', 204);
        }
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Chat emails turned off · Travo</title></head>'
            . '<body style="margin:0;background:#0b1220;font-family:Helvetica,Arial,sans-serif;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px">'
            . '<div style="max-width:440px;background:#111a2e;border:1px solid rgba(148,163,184,.2);border-radius:16px;padding:28px">'
            . '<h1 style="margin:0 0 10px;font-size:22px;color:#fff">Chat emails turned off</h1>'
            . '<p style="margin:0 0 18px;line-height:1.6;color:#cbd5e1">We won\'t email you about new chat messages any more. You\'ll still see them in the app under <b>Chat</b>, and you\'ll still get emails about your bookings and payments.</p>'
            . '<a href="https://travo.ng/#chat" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:bold">Open my chats</a></div></body></html>';

        return response($html, 200)->header('X-Robots-Tag', 'noindex');
    }
}
