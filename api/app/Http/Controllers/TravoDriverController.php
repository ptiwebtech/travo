<?php

namespace App\Http\Controllers;

use App\Support\TravoChat;
use App\Support\TravoDriver;
use App\Support\TravoDriverApplication;
use App\Support\TravoJobOffers;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/** Travo Driver: the web "Navigator" inside Travo (see App\Support\TravoDriver). */
class TravoDriverController extends Controller
{
    private function staff(Request $r): bool
    {
        return $r->user() && TravoChat::isStaff($r->user());
    }

    private function out(array $r)
    {
        return response()->json($r, isset($r['error']) ? 422 : 200);
    }

    /** GET int/v1/travo/driver */
    public function me(Request $request)
    {
        return response()->json(TravoDriver::profile($request->user()));
    }

    /** POST int/v1/travo/driver/register {vehicle, plate, area, phone, license, agree} */
    public function register(Request $request)
    {
        $data = $request->validate([
            'vehicle' => 'required|in:' . implode(',', array_keys(TravoDriver::VEHICLES)),
            'plate'   => 'nullable|string|max:20',
            'area'    => 'required|string|max:120',
            'phone'   => 'required|string|max:30',
            'license' => 'nullable|string|max:40',
            'agree'   => 'accepted',
        ]);

        return $this->out(TravoDriver::register($request->user(), $data));
    }

    /** POST int/v1/travo/driver/application {fields..., nin, account_number, agree, submit} : autosave, or submit */
    public function application(Request $request)
    {
        $in = $request->only(array_merge(array_keys(TravoDriverApplication::FIELDS), array_keys(TravoDriverApplication::SECRET), ['agree']));
        $r  = TravoDriverApplication::save($request->user(), $in, (bool) $request->input('submit'));
        if (isset($r['error'])) {
            return response()->json($r, 422);
        }

        return response()->json($r + ['profile' => TravoDriver::profile($request->user())]);
    }

    /** POST int/v1/travo/driver/docs {type, file} */
    public function doc(Request $request)
    {
        $f = $request->file('file');
        if (!$f || !$f->isValid()) {
            return response()->json(['error' => 'Choose a photo to upload.'], 422);
        }

        return $this->out(TravoDriverApplication::upload($request->user(), (string) $request->input('type'), $f));
    }

    /** GET int/v1/travo/driver/docs/{file} : the applicant or staff */
    public function docFile(Request $request, string $file)
    {
        $f = TravoDriverApplication::docFile($request->user(), $file);
        if (!$f || !Storage::disk('local')->exists($f->path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($f->path), ['Content-Type' => $f->content_type, 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** POST int/v1/travo/driver/reopen : start again after a decline */
    public function reopen(Request $request)
    {
        TravoDriverApplication::reopen($request->user());

        return response()->json(TravoDriver::profile($request->user()));
    }

    /** POST int/v1/travo/driver/offers/{offer} {accept} */
    public function offer(Request $request, string $offer)
    {
        $d = TravoDriver::driverFor($request->user());
        if (!$d) {
            return response()->json(['error' => 'You are not registered as a driver.'], 422);
        }

        return $this->out(TravoJobOffers::respond($request->user(), $d, $offer, (bool) $request->input('accept')));
    }

    /** POST int/v1/travo/driver/online {online} */
    public function online(Request $request)
    {
        $d = TravoDriver::driverFor($request->user());
        if (!$d || $d->status !== 'active') {
            return response()->json(['error' => 'Your driver account is not approved yet.'], 422);
        }
        $on = (bool) $request->input('online');
        TravoDriver::setOnline($d, $on);
        $on ? null : TravoDriver::bgRevoke($d);

        // a fresh background-GPS address for the Travo app (used only when the Median add-on is present)
        return response()->json(['ok' => true, 'online' => $on, 'bg' => $on ? TravoDriver::bgIssue($d) : null]);
    }

    /** POST int/v1/travo/bgloc/{driver}/{token} : background locations from the Travo app (Median); no session */
    public function bgloc(Request $request, string $driver, string $token)
    {
        $payload = $request->json()->all() ?: $request->all();
        $r       = TravoDriver::bgIngest($driver, $token, is_array($payload) ? $payload : []);

        return response()->json(isset($r['error']) ? ['error' => $r['error']] : $r, $r['code'] ?? 200);
    }

    /** POST int/v1/travo/driver/track {latitude, longitude, heading, speed, accuracy} */
    public function track(Request $request)
    {
        $d = TravoDriver::driverFor($request->user());
        if (!$d || $d->status !== 'active') {
            return response()->json(['error' => 'Your driver account is not approved yet.'], 422);
        }
        $n = fn ($k) => is_numeric($request->input($k)) ? (float) $request->input($k) : null;
        if ($n('latitude') === null || $n('longitude') === null) {
            return response()->json(['error' => 'Location missing'], 422);
        }

        return $this->out(TravoDriver::track($d, $n('latitude'), $n('longitude'), $n('heading'), $n('speed'), $n('accuracy')));
    }

    /** POST int/v1/travo/driver/jobs/{order} {action: picked_up|delivered, photo, remarks} */
    public function job(Request $request, string $order)
    {
        $d = TravoDriver::driverFor($request->user());
        if (!$d) {
            return response()->json(['error' => 'You are not registered as a driver.'], 422);
        }
        $data = $request->validate(['action' => 'required|in:picked_up,delivered', 'remarks' => 'nullable|string|max:500']);

        return $this->out(TravoDriver::jobAction($request->user(), $d, $order, $data['action'], $request->file('photo'), (string) ($data['remarks'] ?? '')));
    }

    /** GET int/v1/travo/driver/proof/{file} : the photo, for staff, the customer and the driver */
    public function proof(Request $request, string $file)
    {
        $f = TravoDriver::canSeeProof($request->user(), $file);
        if (!$f || !Storage::disk('local')->exists($f->path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($f->path), ['Content-Type' => $f->content_type, 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    // ---- admin ----------------------------------------------------------------------------------

    /** GET int/v1/fleet-ops/live/drivers (override): online Travo drivers only, as green vehicle icons. */
    public function liveDrivers(Request $request)
    {
        $list = $request->user() ? TravoDriver::liveDrivers($request->user()) : null;

        return $list === null ? app(\Fleetbase\FleetOps\Http\Controllers\Internal\v1\LiveController::class)->drivers() : response()->json($list);
    }

    /** GET int/v1/fleet-ops/live/vehicles (override): parked vehicle records aren't drawn; online drivers show as their vehicle. */
    public function liveVehicles(Request $request)
    {
        $s = TravoDriver::settings();
        if ($request->user() && ($request->user()->company_uuid ?? null) === $s['operator_company']) {
            return response()->json([]);
        }

        return app(\Fleetbase\FleetOps\Http\Controllers\Internal\v1\LiveController::class)->vehicles();
    }

    /** GET int/v1/travo/driver/admin */
    public function adminList(Request $request)
    {
        if (!$this->staff($request)) {
            return response()->json(['error' => 'Only Travo admins can see this.'], 403);
        }

        return response()->json(['drivers' => TravoDriver::adminList(), 'timeoutMin' => TravoDriver::settings()['online_timeout_min']]);
    }

    /** POST int/v1/travo/driver/admin/{id}/review {approve, note} */
    public function review(Request $request, string $id)
    {
        if (!$this->staff($request)) {
            return response()->json(['error' => 'Only Travo admins can do this.'], 403);
        }

        $decision = (string) $request->input('decision', $request->input('approve') ? 'approve' : 'reject');
        if (!in_array($decision, ['approve', 'changes', 'reject'], true)) {
            return response()->json(['error' => 'Unknown decision'], 422);
        }

        return $this->out(TravoDriverApplication::review($id, $decision, $request->input('note'), $request->user()));
    }

    /** GET int/v1/travo/orders-recent?hours=24 : orders and quotes from the last day, for quick assignment (staff) */
    public function recentOrders(Request $request)
    {
        if (!$this->staff($request)) {
            return response()->json(['error' => 'Only Travo admins can see this.'], 403);
        }

        return response()->json(TravoDriver::recentOrders((int) $request->input('hours', 24)));
    }

    /** GET int/v1/travo/driver/admin/{id}/live : live tracking (staff) */
    public function adminLive(Request $request, string $id)
    {
        if (!$this->staff($request)) {
            return response()->json(['error' => 'Only Travo admins can see this.'], 403);
        }

        return $this->out(TravoDriver::live($id, (int) $request->input('hours', 3)));
    }

    /** GET int/v1/travo/driver/admin/{id} : the full application */
    public function adminShow(Request $request, string $id)
    {
        if (!$this->staff($request)) {
            return response()->json(['error' => 'Only Travo admins can see this.'], 403);
        }

        return $this->out(TravoDriverApplication::adminView($id, $request->user()));
    }

    /** POST int/v1/travo/orders/{id}/offers {drivers: [public_id], minutes} | {withdraw: true} */
    public function offers(Request $request, string $id)
    {
        if (!$this->staff($request)) {
            return response()->json(['error' => 'Only Travo admins can do this.'], 403);
        }
        if ($request->input('withdraw')) {
            $o = TravoDriver::findOrder($id);
            if ($o) {
                TravoJobOffers::close($o->uuid, 'withdrawn');
            }

            return response()->json(['ok' => true]);
        }

        return $this->out(TravoJobOffers::offer($id, (array) $request->input('drivers', []), (int) $request->input('minutes', 30), $request->user()));
    }

    /** GET int/v1/travo/orders/{id}/driver */
    public function orderDriver(Request $request, string $id)
    {
        if (!$this->staff($request)) {
            return response()->json(['error' => 'Only Travo admins can see this.'], 403);
        }

        return $this->out(TravoDriver::orderDriver($id));
    }

    /** POST int/v1/travo/orders/{id}/driver {driver: public_id|null} */
    public function assign(Request $request, string $id)
    {
        if (!$this->staff($request)) {
            return response()->json(['error' => 'Only Travo admins can do this.'], 403);
        }

        return $this->out(TravoDriver::assign($id, $request->input('driver')));
    }
}
