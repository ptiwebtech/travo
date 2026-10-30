<?php

namespace App\Http\Controllers;

use App\Support\TravoDrip;
use Illuminate\Http\Request;

/**
 * Travo email calendar opt-out (https://travo.ng/unsubscribe/{code}, proxied to /share/unsubscribe/{code}).
 *
 * GET shows the page and opts the person out straight away via a small script (a POST), so it is one
 * click for people but link-checking robots in company mail systems (which don't run scripts) don't
 * unsubscribe anyone by accident. Without scripts the page shows an "Unsubscribe" button.
 * POST (also used by mail apps' one-click unsubscribe, RFC 8058) opts out; POST with resubscribe=1 opts back in.
 */
class UnsubscribeController extends Controller
{
    public function show(string $code)
    {
        $user = TravoDrip::userFromCode($code);

        return $this->respond(response()->view('unsubscribe', ['code' => $code, 'valid' => (bool) $user, 'done' => false, 'back' => false], $user ? 200 : 404));
    }

    public function update(Request $request, string $code)
    {
        $user = TravoDrip::userFromCode($code);
        if (!$user) {
            return $this->respond(response()->view('unsubscribe', ['code' => $code, 'valid' => false, 'done' => false, 'back' => false], 404));
        }
        $back = $request->input('resubscribe') === '1';
        $back ? TravoDrip::resubscribe($user) : TravoDrip::unsubscribe($user);
        if ($request->expectsJson() || $request->input('ajax') === '1') {
            return response()->json(['ok' => true, 'subscribed' => $back]);
        }

        return $this->respond(response()->view('unsubscribe', ['code' => $code, 'valid' => true, 'done' => !$back, 'back' => $back]));
    }

    private function respond($r)
    {
        return $r->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }
}
