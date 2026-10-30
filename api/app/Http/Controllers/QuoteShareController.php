<?php

namespace App\Http\Controllers;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Travo: public, shareable quote pages for orders.
 *
 *   GET /share/quote/{code}                         public page (Apache serves it as https://travo.ng/quote/{code})
 *   GET /int/v1/travo/orders/{id}/share-link        signed-in console users: returns the link for an order
 *
 * The code is "<order public_id>-<12-char HMAC>", signed with APP_KEY, so links work for every order
 * (old and new) without storing anything, and other orders' links can't be guessed.
 * Shows no names, phone numbers, emails, street addresses or notes; locations are area names
 * plus approximate (~1 km) pins on a map drawn from Travo's own service-area outlines (no map API).
 */
class QuoteShareController extends Controller
{
    public const PUBLIC_BASE = 'https://travo.ng/quote/';

    private const RANK = ['neighborhood' => 0, 'city' => 1, 'region' => 2, 'state' => 3, 'province' => 3, 'country' => 5, 'continent' => 6];

    public static function code(string $publicId): string
    {
        $mac = hash_hmac('sha256', 'travo-quote|' . $publicId, (string) config('app.key'), true);

        return $publicId . '-' . substr(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), 0, 12);
    }

    public static function url(string $publicId): string
    {
        return self::PUBLIC_BASE . self::code($publicId);
    }

    /** Signed-in users: get the share link for an order their company can see. */
    public function link(Request $request, string $id)
    {
        $order = $this->orderForUser($request, $id);
        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json(['url' => self::url($order->public_id)]);
    }

    /**
     * Signed-in users: email an existing order's quote link to someone.
     * Only sends an email and notes the recipient on the order ("Shared With Details", same format the
     * console already shows). It does NOT create a user account for the recipient.
     */
    public function email(Request $request, string $id)
    {
        $order = $this->orderForUser($request, $id);
        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }
        $data = $request->validate([
            'name'  => 'nullable|string|max:120',
            'email' => 'required|email:rfc|max:190',
            'phone' => 'nullable|string|max:40',
        ]);

        $order->load(['payload.pickup', 'payload.dropoff', 'payload.entities', 'purchaseRate.serviceQuote.items']);
        $quote  = $this->buildQuote($order, self::code($order->public_id));
        $sender = $this->clean(optional($request->user())->name) ?: 'Someone';
        $name   = $this->clean($data['name'] ?? '') ?: 'there';
        $isRide = str_contains(strtoupper((string) $order->type), 'DRIVER');
        $subject = ($isRide ? '🚗 ' : '📦 ') . $sender . ' shared a ' . $quote['orderType'] . ' quote with you'
            . ($quote['price'] ? ' (' . $quote['price'] . ')' : '');

        // same design as every other customer email (resources/views/emails/travo/order-step.blade.php)
        $view = OrderFlowController::emailData($order, 'quote', $this->clean($data['name'] ?? '') ?: null, [
            'eyebrow' => 'Quote shared with you',
            'title'   => $sender . ' shared a quote with you',
            'intro'   => 'Here is a quote for ' . $quote['orderType'] . ' on Travo. To book it, make payment using the details below and we will take it from there. The quote is held for 24 hours.',
            'promo'   => true,
        ]);
        Mail::send('emails.travo.order-step', $view, function ($m) use ($data, $subject) {
            $m->to($data['email'], $data['name'] ?? null)->subject($subject);
        });

        // note who it was shared with, in the same format the existing share feature uses
        $meta    = is_array($order->meta) ? $order->meta : (json_decode((string) $order->meta, true) ?: []);
        $current = (string) data_get($meta, 'Shared With Details', '');
        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $current, $found);
        if (!in_array(strtolower($data['email']), array_map('strtolower', $found[0] ?? []), true)) {
            $line = '(' . (count($found[0] ?? []) + 1) . ') ' . ($data['name'] ?? 'Guest') . ' | ' . $data['email'] . ' | ' . ($data['phone'] ?? 'N/A') . "\n";
            $meta['Shared With Details'] = $current . $line;
            $order->updateMeta($meta);
        }

        return response()->json(['status' => 'success', 'url' => $quote['url']]);
    }

    private function orderForUser(Request $request, string $id): ?Order
    {
        $user  = $request->user();
        $order = Order::where('public_id', $id)->first() ?? Order::where('uuid', $id)->first();
        if (!$user || !$order) {
            return null;
        }
        $allowed = $user->company_uuid === $order->company_uuid
            || (method_exists($user, 'isAdmin') && $user->isAdmin())
            || DB::table('company_users')->where('user_uuid', $user->uuid)->where('company_uuid', $order->company_uuid)->exists();

        return $allowed ? $order : null;
    }

    /** Public quote page. */
    public function show(string $code)
    {
        // the signature is always 12 characters and may itself contain "-", so split by length
        $publicId = strlen($code) > 13 && $code[strlen($code) - 13] === '-' ? substr($code, 0, -13) : '';
        $order    = null;

        if ($publicId && hash_equals(self::code($publicId), $code)) {
            $order = Order::where('public_id', $publicId)
                ->with(['payload.pickup', 'payload.dropoff', 'payload.entities', 'purchaseRate.serviceQuote.items'])
                ->first();
        }

        if (!$order) {
            return $this->respond(response()->view('quote-share-missing', [], 404));
        }

        return $this->respond(response()->view('quote-share', $this->buildQuote($order, $code)));
    }

    private function respond($response)
    {
        return $response
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'private, max-age=60');
    }

    public function buildQuote(Order $order, string $code): array
    {
        $meta = is_array($order->meta) ? $order->meta : [];

        // ---- price -------------------------------------------------------------------------
        $quote = optional($order->purchaseRate)->serviceQuote;
        if (!$quote && $order->service_quote_uuid) {
            $quote = ServiceQuote::with('items')->where('uuid', $order->service_quote_uuid)->first();
        }
        // Same rules as the Fleet-Ops "Purchase Rate" panel:
        //  - bus/train tickets: operator + route, tickets x price per ticket, total = meta.total_bus_price (naira)
        //  - charters: rate per day (quote items), number of days, total = meta.charter_total_price (kobo)
        //  - everything else: quote items + add-on services, total = quote amount + meta.custom_additional_services_total (kobo)
        // Quote items/amounts are in kobo; bus prices and add-on service prices are in naira.
        $currency = optional($quote)->currency ?: optional($order->purchaseRate)->currency ?: 'NGN';
        $quoteAmt = optional($quote)->amount ?? optional($order->purchaseRate)->amount;
        $lines    = [];
        $amount   = null;
        $qItems   = ($quote && $quote->items) ? $quote->items : [];
        $naira    = fn ($v) => is_numeric($v) ? (float) $v * 100 : null;

        if ((string) $order->type === 'travel-book-a-bustrain-ticket' && is_numeric($meta['total_bus_price'] ?? null)) {
            $operator = $this->clean($meta['vendor_name'] ?? '');
            $leg      = trim($this->clean($meta['departure_city'] ?? '') . ' ➡ ' . $this->clean($meta['arrival_city'] ?? ''), ' ➡');
            $tickets  = (int) ($meta['tickets'] ?? 1) ?: 1;
            if (is_numeric($meta['price_per_ticket'] ?? null)) {
                $lines[] = ['label' => trim($operator . ($leg ? ' · ' . $leg : '')) ?: 'Tickets', 'amount' => $tickets . ' x ' . $this->money($naira($meta['price_per_ticket']), $currency)];
            }
            $amount = $naira($meta['total_bus_price']);
        } elseif (!empty($meta['charter_days'])) {
            foreach ($qItems as $item) {
                $lines[] = ['label' => ($this->clean($item->details) ?: 'Rate') . ' (rate per day)', 'amount' => $this->money($item->amount, $currency)];
            }
            $lines[] = ['label' => 'Number of days', 'amount' => (int) $meta['charter_days'] . ' day(s)'];
            $amount  = is_numeric($meta['charter_total_price'] ?? null) ? (float) $meta['charter_total_price'] : $quoteAmt;
        } else {
            foreach ($qItems as $item) {
                $lines[] = ['label' => $this->clean($item->details) ?: 'Charge', 'amount' => $this->money($item->amount, $currency)];
            }
            foreach ((array) ($meta['custom_additional_services'] ?? []) as $extra) {
                $extra = (array) $extra;
                if (!empty($extra['name']) && is_numeric($extra['price'] ?? null)) {
                    $lines[] = ['label' => $this->clean($extra['name']), 'amount' => $this->money($naira($extra['price']), $currency)];
                }
            }
            $extrasTotal = is_numeric($meta['custom_additional_services_total'] ?? null) ? (float) $meta['custom_additional_services_total'] : 0;
            $amount      = $quoteAmt !== null ? (float) $quoteAmt + $extrasTotal : null;
        }

        // ---- what -------------------------------------------------------------------------
        $orderType = $this->clean($order->order_type_name ?? ($meta['order_type_name'] ?? null)) ?: Str::headline((string) $order->type);
        $service   = $this->clean($order->service_name ?? ($meta['service_name'] ?? null)) ?: $this->clean(optional($quote)->service_rate_name);
        $items     = [];
        foreach (optional($order->payload)->entities ?? [] as $entity) {
            $name = $this->clean($entity->name ?: $entity->type);
            if ($name && count($items) < 6) {
                $items[] = Str::limit($name, 60);
            }
        }

        // ---- where (area names + approximate points only) -----------------------------------
        $areas   = $this->areas();
        $pickup  = $this->place(optional($order->payload)->pickup, $areas);
        $dropoff = $this->place(optional($order->payload)->dropoff, $areas);

        $distanceKm = $order->distance > 0 ? round($order->distance / 1000, $order->distance < 10000 ? 1 : 0) : null;
        $minutes    = $order->time > 0 ? (int) ceil($order->time / 60) : null;

        $status = Str::headline((string) $order->status ?: 'created');
        $closed = in_array($order->status, ['canceled', 'cancelled', 'expired'], true);

        $priceText = $amount !== null ? $this->money($amount, $currency) : null;
        $routeText = trim(($pickup['label'] ?? '') . ($dropoff ? ' → ' . $dropoff['label'] : ''));

        return [
            'code'       => $code,
            'url'        => self::PUBLIC_BASE . $code,
            'ref'        => $order->public_id,
            'orderType'  => $orderType,
            'service'    => $service,
            'status'     => $status,
            'closed'     => $closed,
            'created'    => optional($order->created_at)->format('j M Y'),
            'scheduled'  => $order->scheduled_at instanceof \DateTimeInterface ? $order->scheduled_at->format('j M Y, g:ia') : null,
            'price'      => $priceText,
            'amount'     => $amount !== null ? (int) round($amount) : null,   // minor units (kobo), same total as 'price'
            'currency'   => $currency,
            'lines'      => $lines,
            'items'      => $items,
            'pickup'     => $pickup,
            'dropoff'    => $dropoff,
            'distanceKm' => $distanceKm,
            'minutes'    => $minutes,
            'map'        => $this->mapSvg($pickup, $dropoff, $areas, $this->routeLine($order)),
            'ogTitle'    => trim(($priceText ? $priceText . ' quote · ' : 'Quote · ') . $orderType),
            'ogText'     => $routeText ? $routeText . ' · Get your own instant quote on Travo.' : 'Get your own instant quote on Travo.',
        ];
    }

    /** Removes anything that looks like a phone number or email from free text. */
    private function clean($text): string
    {
        $text = (string) $text;
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '', $text);
        $text = preg_replace('/\+?\d[\d\s().-]{6,}\d/u', '', $text);

        return trim(preg_replace('/\s{2,}/', ' ', $text), " \t\n\r\0\x0B,-·");
    }

    private function money($minor, string $currency): string
    {
        $symbols = ['NGN' => '₦', 'GHS' => 'GH₵', 'USD' => '$', 'GBP' => '£', 'EUR' => '€', 'KES' => 'KSh ', 'ZAR' => 'R'];
        $value   = ((float) $minor) / 100;

        return ($symbols[strtoupper($currency)] ?? (strtoupper($currency) . ' ')) . number_format($value, fmod($value, 1.0) == 0.0 ? 0 : 2);
    }

    // ---- locations ------------------------------------------------------------------------

    /** Service-area outlines (cities/states/countries) as plain arrays, cached for an hour. */
    private function areas(): array
    {
        return Cache::remember('travo_quote_areas_v1', 3600, function () {
            $out = [];
            foreach (ServiceArea::whereIn('type', ['city', 'state', 'province', 'country'])->get(['name', 'type', 'border']) as $a) {
                $geo   = json_decode(json_encode($a->border), true) ?: [];
                $polys = ($geo['type'] ?? '') === 'Polygon' ? [$geo['coordinates']] : (($geo['type'] ?? '') === 'MultiPolygon' ? $geo['coordinates'] : []);
                $rings = [];
                foreach ($polys as $p) {
                    if (!empty($p[0])) {
                        $rings[] = $p[0];
                    }
                }
                if (!$rings) {
                    continue;
                }
                $out[] = ['name' => $a->name, 'type' => $a->type, 'rank' => self::RANK[$a->type] ?? 4, 'rings' => $rings, 'bbox' => $this->bbox($rings)];
            }

            return $out;
        });
    }

    private function bbox(array $rings): array
    {
        $b = [INF, INF, -INF, -INF];
        foreach ($rings as $r) {
            foreach ($r as $c) {
                $b = [min($b[0], $c[0]), min($b[1], $c[1]), max($b[2], $c[0]), max($b[3], $c[1])];
            }
        }

        return $b;
    }

    private function inRing(float $x, float $y, array $r): bool
    {
        $in = false;
        for ($i = 0, $j = count($r) - 1; $i < count($r); $j = $i++) {
            [$xi, $yi] = $r[$i];
            [$xj, $yj] = $r[$j];
            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $in = !$in;
            }
        }

        return $in;
    }

    private function contains(array $area, array $pt): bool
    {
        [$x, $y] = $pt;
        $b       = $area['bbox'];
        if ($x < $b[0] || $x > $b[2] || $y < $b[1] || $y > $b[3]) {
            return false;
        }
        foreach ($area['rings'] as $r) {
            if ($this->inRing($x, $y, $r)) {
                return true;
            }
        }

        return false;
    }

    private function place($place, array $areas): ?array
    {
        if (!$place) {
            return null;
        }
        $pt = null;
        if ($place->location && method_exists($place->location, 'getLat')) {
            $lat = (float) $place->location->getLat();
            $lng = (float) $place->location->getLng();
            if (abs($lat) > 0.0001 || abs($lng) > 0.0001) {
                // round to ~1 km so the exact spot is never shared
                $pt = [round($lng, 2), round($lat, 2)];
            }
        }

        $state = $country = $city = null;
        if ($pt) {
            foreach ($areas as $a) {
                if (!$this->contains($a, $pt)) {
                    continue;
                }
                if ($a['type'] === 'country') {
                    $country = $country ?? $a;
                } elseif (in_array($a['type'], ['state', 'province'], true)) {
                    $state = $state ?? $a;
                } elseif ($a['type'] === 'city') {
                    $city = $city ?? $a;
                }
            }
        }

        $stateName = $state ? preg_replace('/ State$/', '', $state['name']) : $this->clean($place->province);
        if ($stateName === 'Federal Capital Territory') {
            $stateName = 'Abuja (FCT)';
        }
        $cityName = $this->clean($place->city);
        if ($cityName && $stateName && Str::contains(Str::lower($stateName), Str::lower($cityName))) {
            $cityName = '';   // "Abuja" + "Abuja (FCT)" -> just "Abuja (FCT)"
        }
        $parts = [];
        foreach ([$this->clean($place->neighborhood), $cityName, $stateName] as $p) {
            if ($p && !in_array(Str::lower($p), array_map([Str::class, 'lower'], $parts), true) && !preg_match('/\d{2,}/', $p)) {
                $parts[] = $p;
            }
        }
        if ($country && ($country['name'] ?? '') !== 'Nigeria') {
            $parts[] = $country['name'];
        } elseif (!$country && $place->country && strtoupper($place->country) !== 'NG' && !$parts) {
            $parts[] = strtoupper($place->country);
        }
        $parts = array_slice($parts, 0, 3);

        return [
            'label'   => $parts ? implode(', ', $parts) : 'Location shared on booking',
            'pt'      => $pt,
            'state'   => $state['name'] ?? null,
            'country' => $country['name'] ?? null,
        ];
    }

    // ---- map (inline SVG, no map service) ------------------------------------------------

    /**
     * The road route Fleet-Ops already stored for the order (routes.details.coordinates), as [lng, lat] points.
     * The first and last stretch is cut off so the line never points at the exact pickup/dropoff address.
     */
    private function routeLine(Order $order): array
    {
        if (!$order->route_uuid) {
            return [];
        }
        $details = json_decode((string) DB::table('routes')->where('uuid', $order->route_uuid)->value('details'), true);
        $pts     = [];
        foreach ((array) ($details['coordinates'] ?? []) as $c) {
            if (isset($c['lat'], $c['lng']) && is_numeric($c['lat']) && is_numeric($c['lng'])) {
                $pts[] = [(float) $c['lng'], (float) $c['lat']];
            }
        }
        if (count($pts) < 2) {
            return [];
        }
        $km  = function ($p, $q) {
            $dLat = deg2rad($q[1] - $p[1]);
            $dLng = deg2rad($q[0] - $p[0]);
            $h    = sin($dLat / 2) ** 2 + cos(deg2rad($p[1])) * cos(deg2rad($q[1])) * sin($dLng / 2) ** 2;

            return 12742 * asin(min(1, sqrt($h)));
        };
        $cum = [0.0];
        for ($i = 1; $i < count($pts); $i++) {
            $cum[$i] = $cum[$i - 1] + $km($pts[$i - 1], $pts[$i]);
        }
        $total = end($cum);
        if ($total < 3) {
            return [];   // very short trips: pins only
        }
        $cut = min(1.5, $total * 0.15);
        $out = [];
        foreach ($pts as $i => $p) {
            if ($cum[$i] >= $cut && $cum[$i] <= $total - $cut) {
                $out[] = $p;
            }
        }
        // at most ~400 points
        if (count($out) > 400) {
            $step = (int) ceil(count($out) / 400);
            $out  = array_values(array_filter($out, fn ($v, $k) => $k % $step === 0, ARRAY_FILTER_USE_BOTH));
        }

        return count($out) >= 2 ? $out : [];
    }

    private function mapSvg(?array $a, ?array $b, array $areas, array $route = []): ?string
    {
        $pts = array_values(array_filter([$a['pt'] ?? null, $b['pt'] ?? null]));
        if (!$pts) {
            return null;
        }
        $framePts = array_merge($pts, $route);
        $find = function ($name) use ($areas) {
            foreach ($areas as $x) {
                if ($x['name'] === $name) {
                    return $x;
                }
            }

            return null;
        };

        $base = [];   // outlines drawn lightly
        $hi   = [];   // outlines highlighted
        $sa   = $a['state'] ?? null;
        $sb   = $b['state'] ?? $sa;
        if ($sa && $sa === $sb) {
            $state = $find($sa);
            $hi[]  = $state;
            foreach ($areas as $x) {   // e.g. Lagos Island / Mainland East / West inside Lagos State
                if ($x['type'] === 'city' && $state && $this->contains($state, [($x['bbox'][0] + $x['bbox'][2]) / 2, ($x['bbox'][1] + $x['bbox'][3]) / 2])) {
                    $base[] = $x;
                }
            }
            $frame = $state['bbox'];
            // short trips: zoom in around the two points (at least ~25 km x 17 km) instead of the whole state
            $px = array_column($framePts, 0);
            $py = array_column($framePts, 1);
            if ($frame && (max($px) - min($px)) < ($frame[2] - $frame[0]) * 0.25 && (max($py) - min($py)) < ($frame[3] - $frame[1]) * 0.25) {
                $cx    = (min($px) + max($px)) / 2;
                $cy    = (min($py) + max($py)) / 2;
                $hw    = max((max($px) - min($px)) * 0.8, 0.11);
                $hh    = max((max($py) - min($py)) * 0.8, 0.075);
                $frame = [$cx - $hw, $cy - $hh, $cx + $hw, $cy + $hh];
            }
        } elseif (($a['country'] ?? null) === 'Nigeria' && ($b['country'] ?? 'Nigeria') === 'Nigeria' && $find('Nigeria')) {
            $ng    = $find('Nigeria');
            $base  = array_values(array_filter($areas, fn ($x) => in_array($x['type'], ['state', 'province'], true)));
            $hi    = array_values(array_filter([$find($sa), $find($sb)]));
            $base  = array_merge([$ng], $base);
            $frame = $ng['bbox'];
        } else {
            foreach (array_unique(array_filter([$a['country'] ?? null, $b['country'] ?? null])) as $c) {
                if ($x = $find($c)) {
                    $hi[] = $x;
                }
            }
            $frame = $hi ? $this->bbox(array_merge(...array_map(fn ($x) => $x['rings'], $hi))) : null;
        }
        $hi = array_values(array_filter($hi));

        // frame must include both points, with padding
        $xs   = array_column($framePts, 0);
        $ys   = array_column($framePts, 1);
        $f    = $frame ?: [min($xs), min($ys), max($xs), max($ys)];
        $f    = [min($f[0], min($xs)), min($f[1], min($ys)), max($f[2], max($xs)), max($f[3], max($ys))];
        $padX = max(($f[2] - $f[0]) * 0.08, 0.03);
        $padY = max(($f[3] - $f[1]) * 0.08, 0.03);
        $f    = [$f[0] - $padX, $f[1] - $padY, $f[2] + $padX, $f[3] + $padY];

        $W  = 640;
        $H  = 400;
        $k  = cos(deg2rad(($f[1] + $f[3]) / 2));
        $sx = $W / max(($f[2] - $f[0]) * $k, 1e-6);
        $sy = $H / max($f[3] - $f[1], 1e-6);
        $s  = min($sx, $sy);
        $ox = ($W - ($f[2] - $f[0]) * $k * $s) / 2;
        $oy = ($H - ($f[3] - $f[1]) * $s) / 2;
        $proj = fn ($c) => [round($ox + ($c[0] - $f[0]) * $k * $s, 1), round($oy + ($f[3] - $c[1]) * $s, 1)];

        $path = function (array $area) use ($proj) {
            $d = '';
            foreach ($area['rings'] as $r) {
                $last = null;
                $seg  = '';
                foreach ($r as $c) {
                    $p = $proj($c);
                    if ($last && abs($p[0] - $last[0]) + abs($p[1] - $last[1]) < 1.5) {
                        continue;
                    }
                    $seg .= ($seg === '' ? 'M' : 'L') . $p[0] . ' ' . $p[1];
                    $last = $p;
                }
                if (strlen($seg) > 12) {
                    $d .= $seg . 'Z';
                }
            }

            return $d;
        };

        $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Approximate route map">';
        $svg .= '<rect width="100%" height="100%" fill="#0e1626"/>';
        foreach ($base as $x) {
            if ($x && !in_array($x, $hi, true)) {
                $svg .= '<path d="' . $path($x) . '" fill="#16233a" stroke="#2b3d5c" stroke-width="1"/>';
            }
        }
        foreach ($hi as $x) {
            $svg .= '<path d="' . $path($x) . '" fill="rgba(59,130,246,.16)" stroke="#3b82f6" stroke-width="1.5"/>';
        }

        $P = array_map($proj, $pts);
        if (count($P) === 2) {
            [$p1, $p2] = $P;
            $dx = $p2[0] - $p1[0];
            $dy = $p2[1] - $p1[1];
            $len = sqrt($dx * $dx + $dy * $dy);
            if ($route) {   // the stored road route (ends trimmed)
                $line = '';
                $last = null;
                foreach ($route as $c) {
                    $q = $proj($c);
                    if ($last && abs($q[0] - $last[0]) + abs($q[1] - $last[1]) < 1.2) {
                        continue;
                    }
                    $line .= ($line === '' ? 'M' : 'L') . $q[0] . ' ' . $q[1];
                    $last = $q;
                }
                $svg .= '<path d="' . $line . '" fill="none" stroke="#0e1626" stroke-width="7" stroke-linejoin="round" stroke-linecap="round" opacity=".6"/>';
                $svg .= '<path d="' . $line . '" fill="none" stroke="#f87171" stroke-width="3.5" stroke-linejoin="round" stroke-linecap="round"/>';
            }
            if ($len < 6) {   // same area: nudge apart so both pins show
                $p1[0] -= 7;
                $p2[0] += 7;
            } elseif (!$route) {
                $cx = ($p1[0] + $p2[0]) / 2 - $dy * 0.18;
                $cy = ($p1[1] + $p2[1]) / 2 + $dx * 0.18;
                $svg .= '<path d="M' . $p1[0] . ' ' . $p1[1] . ' Q' . round($cx, 1) . ' ' . round($cy, 1) . ' ' . $p2[0] . ' ' . $p2[1] . '" fill="none" stroke="#93c5fd" stroke-width="2.5" stroke-dasharray="7 6" stroke-linecap="round"/>';
            }
            $P = [$p1, $p2];
        }
        $colours = ['#34d399', '#60a5fa'];
        $letters = ['A', 'B'];
        foreach ($P as $i => $p) {
            $c = $colours[$i] ?? '#60a5fa';
            $svg .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="22" fill="' . $c . '" opacity=".18"/>';
            $svg .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="11" fill="' . $c . '" stroke="#0e1626" stroke-width="3"/>';
            $svg .= '<text x="' . $p[0] . '" y="' . ($p[1] + 4) . '" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="11" font-weight="700" fill="#0e1626">' . ($letters[$i] ?? '') . '</text>';
        }

        return $svg . '</svg>';
    }
}
