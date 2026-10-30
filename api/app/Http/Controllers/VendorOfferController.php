<?php

namespace App\Http\Controllers;

use App\Support\TravoVendorOffers;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Travo vendor job offers: the vendor's offer page (public, by token) and the admin list on the order page. */
class VendorOfferController extends Controller
{
    private function view(array $data)
    {
        return response()->view('travo-vendor-offer', $data)->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store');
    }

    private function token(string $token): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', $token);
    }

    /** GET shows the job; POST do=accept|decline. */
    public function show(Request $request, string $token)
    {
        $offer = TravoVendorOffers::byToken($this->token($token));
        if (!$offer) {
            return $this->view(['valid' => false, 'mode' => 'offer']);
        }
        $flash = null;
        if ($request->isMethod('post') && in_array($request->input('do'), ['accept', 'decline'], true)) {
            [, $flash] = TravoVendorOffers::respond($offer, $request->input('do') === 'accept');
            $offer     = TravoVendorOffers::byToken($offer->token);
        }

        return $this->view(TravoVendorOffers::page($offer) + [
            'valid' => true, 'mode' => 'offer', 'flash' => $flash,
            'stopUrl' => TravoVendorOffers::url($offer->token) . '/stop',
        ]);
    }

    /** GET asks to confirm; POST (or a one-click List-Unsubscribe POST) takes the vendor off the vendor lists. */
    public function stop(Request $request, string $token)
    {
        $offer = TravoVendorOffers::byToken($this->token($token));
        if (!$offer) {
            return $this->view(['valid' => false, 'mode' => 'stop']);
        }
        if ($request->isMethod('post')) {
            TravoVendorOffers::optOut($offer->vendor_uuid);
        }
        $vendor = DB::table('vendors')->where('uuid', $offer->vendor_uuid)->value('name');

        return $this->view([
            'valid' => true, 'mode' => 'stop', 'vendor' => trim((string) $vendor) ?: 'Your company',
            'optedOut' => TravoVendorOffers::optedOut($offer->vendor_uuid),
        ]);
    }

    private function staff(Request $request): bool
    {
        $u = $request->user();

        return $u && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    /** Admin: vendors who accepted this order's offer (and counts). */
    public function forOrder(Request $request, string $id)
    {
        if (!$this->staff($request)) {
            return response()->json(['error' => 'Only Travo admins can see this.'], 403);
        }
        $order = Order::where('public_id', $id)->orWhere('uuid', $id)->first();
        if (!$order) {
            return response()->json(['error' => 'Order not found.'], 404);
        }

        return response()->json(TravoVendorOffers::forOrder($order));
    }
}
