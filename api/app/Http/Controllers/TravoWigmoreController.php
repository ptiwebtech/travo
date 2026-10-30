<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Wigmore shop products for the console home card, always in naira.
 * Wigmore's own feed sometimes answers in pounds with a broken exchange rate (₦9,500 shown as £ millions),
 * depending on the visitor. From our server it answers in naira; we keep a naira-only answer for 10 minutes.
 * GET int/v1/travo/wigmore/products
 */
class TravoWigmoreController extends Controller
{
    public function products()
    {
        $data = Cache::get('travo-wigmore-products');
        if (!$data) {
            for ($i = 0; $i < 3 && !$data; $i++) {
                try {
                    $r = Http::timeout(8)
                        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Travo console)', 'Cookie' => 'currency=NGN'])
                        ->get('https://wigmorewholesale.com/index.php', ['route' => 'api/random_product']);
                    $d = $r->ok() ? ($r->json('data') ?: []) : [];
                    $ok = count($d) > 0;
                    foreach ($d as $p) {
                        if (strpos((string) ($p['price'] ?? ''), '₦') !== 0) { $ok = false; break; }
                    }
                    if ($ok) {
                        $data = [];
                        foreach (array_slice($d, 0, 9) as $p) {
                            $data[] = [
                                'name'  => html_entity_decode((string) ($p['name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                                'price' => (string) $p['price'],
                                'image' => (string) ($p['image'] ?? ''),
                                'href'  => (string) ($p['href'] ?? ''),
                            ];
                        }
                        Cache::put('travo-wigmore-products', $data, 600);
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        return response()->json(['data' => $data ?: []]);
    }
}
