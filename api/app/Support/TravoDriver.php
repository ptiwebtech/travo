<?php

namespace App\Support;

use App\Http\Controllers\OrderFlowController;
use App\Http\Controllers\QuoteShareController;
use Fleetbase\FleetOps\Events\DriverLocationChanged;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Travo Driver (28 Oct 2026): "Navigator inside Travo" for the web.
 *
 *  - Any signed-in Travo user can apply to drive (travo.ng/#drive). That creates a Fleet-Ops driver in the
 *    operating company (Ptiweb, where admins, drivers and vendors live), status "pending" until an admin approves.
 *  - Approved drivers go online in the Travo app; while the page is open their phone's GPS is sent here and
 *    stored exactly like Navigator's drivers/{id}/track (location point, heading, speed, DriverLocationChanged
 *    broadcast, position history), so they appear on the Fleet-Ops live map and on Travo's "Drivers live" map.
 *  - Admins assign a driver to an order. The driver sees the job, navigates, marks it "Picked up" (order ->
 *    dispatched, only once paid) and "Delivered" with a photo (Fleet-Ops proof of delivery; order -> delivered).
 *    The usual Travo steps, customer emails, tracking page and alerts follow from OrderFlowController.
 *
 * Settings: storage/app/travo-driver.json. Driver details live in drivers.meta.travo.
 */
class TravoDriver
{
    public const VEHICLES = [
        'bike' => 'Motorbike', 'car' => 'Car', 'minivan' => 'Mini van', 'van' => 'Van',
        'truck' => 'Truck', 'bus' => 'Bus', 'tanker' => 'Tanker',
    ];

    /** Green top-down vehicle icons (served by the console nginx), used on the Fleet-Ops live map and the Travo map. */
    public const ICON_BASE = 'https://travo.ng/travo/vehicle-icons/';

    public static function vehicleIcon(?string $type): string
    {
        return self::ICON_BASE . (isset(self::VEHICLES[$type ?? '']) ? $type : 'car') . '.svg';
    }

    /**
     * Fleet-Ops live map, drivers layer (replaces fleet-ops/live/drivers for the operating company): only approved
     * drivers who are online in Drive with Travo right now (GPS in the last few minutes), each drawn as their own
     * vehicle type in green. Other companies get Fleet-Ops' normal answer.
     */
    public static function liveDrivers(object $user)
    {
        $s = self::settings();
        if (($user->company_uuid ?? null) !== $s['operator_company']) {
            return null;
        }
        $rows = Driver::where('company_uuid', $s['operator_company'])->whereNull('deleted_at')->where('status', 'active')->where('online', 1)
            ->with(['vehicle'])->get()
            ->filter(fn ($d) => self::isOnline($d) && $d->location && abs($d->location->getLat()) > 0.0001)->values();
        $out = \Fleetbase\FleetOps\Http\Resources\v1\Driver::collection($rows)->response()->getData(true);
        $list = isset($out['data']) ? $out['data'] : $out;
        foreach ($list as $i => $row) {
            $d    = $rows[$i];
            $type = data_get(self::meta($d), 'travo.vehicle') ?: data_get($d->vehicle ? json_decode((string) DB::table('vehicles')->where('uuid', $d->vehicle_uuid)->value('meta'), true) : [], 'travo.type');
            $list[$i]['vehicle_avatar'] = self::vehicleIcon($type);
        }

        return $list;
    }

    public const DEFAULTS = [
        'operator_company'   => null,    // company uuid drivers belong to (default: the support login's company)
        'staff_email'        => 'hello@travoservices.com',
        'online_timeout_min' => 5,       // no GPS for this long = shown offline
        'min_track_seconds'  => 8,       // ignore GPS updates faster than this
    ];

    // ---- settings -----------------------------------------------------------------------------

    public static function settings(): array
    {
        $p = storage_path('app/travo-driver.json');
        $s = is_file($p) ? json_decode((string) file_get_contents($p), true) : [];
        $s = array_merge(self::DEFAULTS, is_array($s) ? $s : []);
        if (!$s['operator_company']) {
            $s['operator_company'] = optional(TravoChat::supportUsers()->first())->company_uuid;
        }

        return $s;
    }

    public static function saveSettings(array $s): void
    {
        $p   = storage_path('app/travo-driver.json');
        $old = is_file($p) ? (json_decode((string) file_get_contents($p), true) ?: []) : [];
        file_put_contents($p, json_encode(array_merge($old, $s), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    // ---- driver records -------------------------------------------------------------------------

    public static function driverFor(object $user): ?Driver
    {
        return Driver::where('user_uuid', $user->uuid)->where('company_uuid', self::settings()['operator_company'])->whereNull('deleted_at')->first();
    }

    public static function find(string $id): ?Driver
    {
        return Driver::where(function ($q) use ($id) { $q->where('public_id', $id)->orWhere('uuid', $id); })
            ->where('company_uuid', self::settings()['operator_company'])->whereNull('deleted_at')->first();
    }

    public static function meta(Driver $d): array
    {
        $m = DB::table('drivers')->where('uuid', $d->uuid)->value('meta');
        $m = is_string($m) ? json_decode($m, true) : (array) $m;

        return is_array($m) ? $m : [];
    }

    public static function saveMetaPublic(Driver $d, array $travo): void
    {
        self::saveMeta($d, $travo);
    }

    private static function saveMeta(Driver $d, array $travo): void
    {
        $m          = self::meta($d);
        $m['travo'] = array_merge((array) ($m['travo'] ?? []), $travo);
        DB::table('drivers')->where('uuid', $d->uuid)->update(['meta' => json_encode($m)]);
    }

    public static function isOnline(Driver $d): bool
    {
        if (!$d->online) {
            return false;
        }
        if ($d->updated_at && Carbon::parse($d->updated_at)->gt(now()->subMinutes((int) self::settings()['online_timeout_min']))) {
            return true;
        }
        // Travo app with background tracking: a parked phone sends nothing until it moves, so allow a longer quiet spell
        $t = (array) (self::meta($d)['travo'] ?? []);

        return !empty($t['bg_token']) && !empty($t['bg_last']) && Carbon::parse($t['bg_last'])->gt(now()->subMinutes(30));
    }

    /** Everything the driver page needs. */
    public static function profile(object $user): array
    {
        $d = self::driverFor($user);
        if (!$d) {
            return ['driver' => null, 'application' => TravoDriverApplication::view($user)] + self::formInfo($user);
        }
        $t = (array) (self::meta($d)['travo'] ?? []);

        return [
            'driver' => [
                'id'        => $d->public_id,
                'status'    => $d->status === 'active' ? 'approved' : ($d->status === 'rejected' ? 'rejected' : 'pending'),
                'online'    => self::isOnline($d),
                'vehicle'   => $t['vehicle'] ?? null,
                'vehicleLabel' => self::VEHICLES[$t['vehicle'] ?? ''] ?? null,
                'plate'     => $t['plate'] ?? null,
                'area'      => $t['area'] ?? null,
                'license'   => $d->drivers_license_number,
                'appliedAt' => $t['applied_at'] ?? null,
                'note'      => $t['review_note'] ?? null,
                'lastSeen'  => optional($d->updated_at)->toIso8601String(),
            ],
            'jobs'        => $d->status === 'active' ? self::jobs($d) : [],
            'offers'      => $d->status === 'active' ? TravoJobOffers::forDriver($d) : [],
            'insights'    => $d->status === 'active' ? rescue(fn () => TravoDriverWelcome::insights($d, $t['vehicle'] ?? null), null, true) : null,
            'application' => TravoDriverApplication::view($user),
        ] + self::formInfo($user);
    }

    private static function formInfo(object $user): array
    {
        return ['vehicles' => self::VEHICLES, 'docTypes' => TravoDriverApplication::DOCS, 'states' => TravoDriverApplication::STATES,
            'phone' => $user->phone ?? null, 'name' => $user->name ?? null];
    }

    public static function register(object $user, array $data): array
    {
        $s = self::settings();
        if (!$s['operator_company']) {
            return ['error' => 'Driver sign-up is not set up yet. Please contact Travo.'];
        }
        $d = self::driverFor($user);
        $travo = [
            'vehicle'    => $data['vehicle'],
            'plate'      => Str::upper(trim((string) ($data['plate'] ?? ''))),
            'area'       => trim((string) ($data['area'] ?? '')),
            'phone'      => trim((string) ($data['phone'] ?? '')),
            'agreed_at'  => now()->toIso8601String(),
        ];
        $isNew = !$d;
        if (!$d) {
            $d = Driver::create([
                'company_uuid'           => $s['operator_company'],
                'user_uuid'              => $user->uuid,
                'drivers_license_number' => trim((string) ($data['license'] ?? '')) ?: null,
                'status'                 => 'pending',
                'online'                 => 0,
                'city'                   => Str::limit($travo['area'], 60, ''),
                'country'                => 'NG',
            ]);
            $travo['applied_at'] = now()->toIso8601String();
        } else {
            $d->drivers_license_number = trim((string) ($data['license'] ?? '')) ?: $d->drivers_license_number;
            $applied = !empty((self::meta($d)['travo'] ?? [])['applied_at']);
            if ($d->status === 'rejected' || (!$applied && $d->status !== 'active')) {
                // re-applying after a rejection, or an old Fleet-Ops record completing a Travo application
                $d->status           = 'pending';
                $travo['applied_at'] = now()->toIso8601String();
                $isNew               = true;   // tell staff, same as a new application
            }
            $d->save();
        }
        self::saveMeta($d, $travo);
        if ($travo['phone'] && empty($user->phone)) {
            rescue(fn () => DB::table('users')->where('uuid', $user->uuid)->update(['phone' => $travo['phone']]), null, false);
        }
        if ($isNew) {
            self::mail($s['staff_email'], '[Travo] New driver application: ' . ($user->name ?: $user->email),
                'New driver application',
                [($user->name ?: 'Someone') . ' (' . $user->email . ($travo['phone'] ? ', ' . $travo['phone'] : '') . ') wants to drive with Travo.',
                 'Vehicle: ' . (self::VEHICLES[$travo['vehicle']] ?? $travo['vehicle']) . ($travo['plate'] ? ' · ' . $travo['plate'] : '') . ($travo['area'] ? ' · ' . $travo['area'] : ''),
                 'Approve or decline them in the Travo console: Drivers (admin).'],
                'https://travo.ng/#drivers-admin', 'Review the application');
        }

        return ['ok' => true] + self::profile($user);
    }

    public static function setOnline(Driver $d, bool $on): void
    {
        $d->online = $on ? 1 : 0;
        $d->save();
    }

    /** Same as Fleet-Ops' drivers/{id}/track, for the web driver page. */
    public static function track(Driver $d, float $lat, float $lng, ?float $heading = null, ?float $speed = null, ?float $accuracy = null): array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || (abs($lat) < 0.0001 && abs($lng) < 0.0001)) {
            return ['error' => 'Invalid location'];
        }
        $s = self::settings();
        if ($d->online && $d->updated_at && Carbon::parse($d->updated_at)->gt(now()->subSeconds((int) $s['min_track_seconds']))) {
            return ['ok' => true, 'skipped' => true];
        }
        $d->update([
            'location' => new Point($lat, $lng),
            'heading'  => $heading !== null && $heading >= 0 ? $heading : $d->heading,
            'speed'    => $speed !== null && $speed >= 0 ? $speed : $d->speed,
            'online'   => 1,
        ]);
        if ($accuracy !== null) {
            self::saveMeta($d, ['accuracy' => (int) round($accuracy), 'last_fix' => now()->toIso8601String()]);
        }
        $prev = session('company');
        session(['company' => $d->company_uuid]);
        rescue(fn () => broadcast(new DriverLocationChanged($d)), null, false);
        rescue(fn () => $d->updatePosition(), null, false);
        session(['company' => $prev]);

        return ['ok' => true];
    }

    // ---- jobs -----------------------------------------------------------------------------------

    public static function orderView(Order $o, bool $forDriver): array
    {
        $q     = rescue(fn () => app(QuoteShareController::class)->buildQuote($o, QuoteShareController::code($o->public_id)), [], false) ?: [];
        $state = OrderFlowController::state($o);
        $pt    = function ($place) {
            $loc = optional($place)->location;
            return $loc ? ['lat' => $loc->getLat(), 'lng' => $loc->getLng()] : null;
        };
        $addr = function ($place) {
            if (!$place) {
                return null;
            }
            $a = rescue(fn () => $place->address, null, false);
            if (!$a) {
                $a = implode(', ', array_filter([$place->name ?? null, $place->street1 ?? null, $place->city ?? null]));
            }

            return trim((string) $a) ?: null;
        };
        $pickup  = rescue(fn () => $o->payload->pickup, null, false);
        $dropoff = rescue(fn () => $o->payload->dropoff, null, false);
        $owner   = DB::table('users')->where('uuid', $o->created_by_uuid ?: $o->customer_uuid)->first(['name', 'phone']);
        $next    = $state['next']['key'] ?? null;

        return [
            'id'       => $o->public_id,
            'ref'      => 'TRV-' . Str::upper(Str::after((string) $o->public_id, 'order_')),
            'service'  => trim(preg_replace('/^[A-Z &]+ - /', '', (string) ($q['orderType'] ?? 'Booking'))),
            // exact addresses for the driver (the public quote page only shows the area)
            'pickup'   => ['label' => $addr($pickup), 'at' => $pt($pickup)],
            'dropoff'  => ['label' => $addr($dropoff), 'at' => $pt($dropoff)],
            'when'     => optional($o->scheduled_at)->toIso8601String(),
            'notes'    => Str::limit((string) $o->notes, 500),
            'customer' => $forDriver ? ['name' => optional($owner)->name, 'phone' => optional($owner)->phone] : null,
            'steps'    => $state['steps'] ?? [],
            'next'     => $next,
            'canceled' => (bool) ($state['canceled'] ?? false),
            'paid'     => in_array($next, ['dispatched', 'delivered', null], true) && !($state['canceled'] ?? false),
            'done'     => $next === null && !($state['canceled'] ?? false),
        ];
    }

    public static function jobs(Driver $d): array
    {
        $open = Order::where('driver_assigned_uuid', $d->uuid)->whereNull('deleted_at')
            ->whereNotIn('status', ['completed', 'canceled'])->orderByDesc('created_at')->limit(20)->get();
        $done = Order::where('driver_assigned_uuid', $d->uuid)->whereNull('deleted_at')
            ->where('status', 'completed')->orderByDesc('updated_at')->limit(5)->get();

        return [
            'open' => $open->map(fn ($o) => self::orderView($o, true))->values()->all(),
            'done' => $done->map(fn ($o) => self::orderView($o, true))->values()->all(),
        ];
    }

    public static function findOrder(string $id): ?Order
    {
        return Order::where(function ($q) use ($id) { $q->where('public_id', $id)->orWhere('uuid', $id); })->whereNull('deleted_at')->first();
    }

    /** picked_up -> "dispatched", delivered -> "delivered" (with photo proof). */
    public static function jobAction(object $user, Driver $d, string $orderId, string $action, ?UploadedFile $photo, string $remarks = ''): array
    {
        if ($d->status !== 'active') {
            return ['error' => 'Your driver account is not approved yet.'];
        }
        $o = self::findOrder($orderId);
        if (!$o || $o->driver_assigned_uuid !== $d->uuid) {
            return ['error' => 'This job is not assigned to you.'];
        }
        $state = OrderFlowController::state($o, true);
        if ($state['canceled'] ?? false) {
            return ['error' => 'This order was canceled. Please don\'t collect or deliver it.'];
        }
        $step = $action === 'picked_up' ? 'dispatched' : 'delivered';
        $next = $state['next']['key'] ?? null;
        if ($next === 'paid') {
            return ['error' => 'Payment for this order hasn\'t been received yet. Please don\'t collect it until it shows as Paid.'];
        }
        if ($next !== $step) {
            return ['error' => $step === 'dispatched' ? 'This job is already marked as picked up.' : 'Mark the job as picked up first.'];
        }
        if ($step === 'delivered' && !$photo) {
            return ['error' => 'Add a photo of the delivery (the item at the drop-off, or the person who received it).'];
        }
        $proofId = null;
        if ($photo) {
            $ext = Str::lower($photo->getClientOriginalExtension() ?: $photo->guessExtension() ?: 'jpg');
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic'], true) || $photo->getSize() > 12 * 1024 * 1024) {
                return ['error' => 'The photo must be a JPG, PNG, WEBP or HEIC up to 12 MB.'];
            }
            $fuid = (string) Str::uuid();
            $path = 'travo-pod/' . now()->format('Y/m') . '/' . $fuid . '.' . $ext;
            Storage::disk('local')->put($path, file_get_contents($photo->getRealPath()));
            DB::table('files')->insert([
                'uuid' => $fuid, 'public_id' => 'file_' . Str::random(7), 'company_uuid' => $o->company_uuid, 'uploader_uuid' => $user->uuid,
                'subject_uuid' => $o->uuid, 'subject_type' => 'fleet-ops:order', 'disk' => 'local', 'path' => $path, 'folder' => 'travo-pod',
                'original_filename' => ($step === 'delivered' ? 'delivery-' : 'pickup-') . $o->public_id . '.' . $ext, 'type' => 'proof_of_delivery',
                'content_type' => $photo->getMimeType() ?: 'image/jpeg', 'file_size' => $photo->getSize(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $proofId = 'proof_' . Str::random(7);
            DB::table('proofs')->insert([
                'uuid' => (string) Str::uuid(), 'public_id' => $proofId, 'order_uuid' => $o->uuid, 'subject_uuid' => $o->uuid, 'subject_type' => 'fleet-ops:order',
                'company_uuid' => $o->company_uuid, 'file_uuid' => $fuid, 'remarks' => Str::limit(trim($remarks), 500, '') ?: null,
                'data' => json_encode(['by' => 'travo-driver', 'driver' => $d->public_id, 'step' => $step,
                    'at' => now()->toIso8601String(), 'location' => $d->location ? ['lat' => $d->location->getLat(), 'lng' => $d->location->getLng()] : null]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $name = $user->name ?: 'your driver';
        OrderFlowController::applyStep($o, $step, $user,
            $step === 'dispatched' ? 'Collected by ' . $name . ' and on its way.' : 'Delivered by ' . $name . ($remarks ? ' (' . Str::limit($remarks, 120) . ')' : '') . '.',
            ['driver' => $d->public_id, 'proof' => $proofId]);
        $mail = rescue(fn () => OrderFlowController::sendStepEmail($o->fresh(), $step), ['sent' => false], false);
        if (!empty($mail['sent'])) {
            $flow                   = (array) data_get($o->fresh()->meta, 'travo_flow', []);
            $flow[$step]['emailed'] = $mail['to'];
            $o->fresh()->updateMeta('travo_flow', $flow);
        }

        return ['ok' => true, 'job' => self::orderView($o->fresh(), true)];
    }

    // ---- admin ----------------------------------------------------------------------------------

    public static function adminList(): array
    {
        $s    = self::settings();
        $rows = Driver::where('company_uuid', $s['operator_company'])->whereNull('deleted_at')->orderByDesc('updated_at')->get();
        $users = DB::table('users')->whereIn('uuid', $rows->pluck('user_uuid')->filter())->get(['uuid', 'name', 'email', 'phone'])->keyBy('uuid');
        $apps  = TravoDriverApplication::statusFor($rows->pluck('uuid')->all());
        $jobs  = Order::whereIn('driver_assigned_uuid', $rows->pluck('uuid'))->whereNull('deleted_at')->whereNotIn('status', ['completed', 'canceled'])
            ->get(['public_id', 'driver_assigned_uuid'])->groupBy('driver_assigned_uuid');

        return $rows->map(function ($d) use ($users, $jobs, $apps) {
            $u = $users[$d->user_uuid] ?? null;
            $t = (array) (self::meta($d)['travo'] ?? []);
            $loc = $d->location;

            return [
                'id'       => $d->public_id,
                'name'     => optional($u)->name ?: 'Driver',
                'email'    => optional($u)->email,
                'phone'    => ($t['phone'] ?? null) ?: optional($u)->phone,
                'status'   => $d->status === 'active' ? 'approved' : ($d->status === 'rejected' ? 'rejected' : ($d->status ?: 'pending')),
                'travo'    => !empty($t),
                'vehicle'  => self::VEHICLES[$t['vehicle'] ?? ''] ?? null,
                'icon'     => self::vehicleIcon($t['vehicle'] ?? null),
                'plate'    => $t['plate'] ?? null,
                'area'     => ($t['area'] ?? null) ?: $d->city,
                'online'   => self::isOnline($d),
                'at'       => ($loc && abs($loc->getLat()) > 0.0001) ? ['lat' => $loc->getLat(), 'lng' => $loc->getLng()] : null,
                'heading'  => $d->heading,
                'accuracy' => $t['accuracy'] ?? null,
                'lastSeen' => optional($d->updated_at)->toIso8601String(),
                'applied'  => $t['applied_at'] ?? null,
                'app'      => $apps[$d->uuid] ?? null,
                'jobs'     => collect($jobs[$d->uuid] ?? [])->map(fn ($o) => 'TRV-' . Str::upper(Str::after($o->public_id, 'order_')))->values()->all(),
            ];
        })->values()->all();
    }

    // ---- background GPS from the Travo app (Median "Background Location" add-on) -----------------

    /**
     * A fresh per-driver posting address, issued each time they go online. Median posts locations to it in the
     * background (session cookies aren't sent, so the token is in the path). Only a hash is stored.
     */
    public static function bgIssue(Driver $d): array
    {
        $tok = Str::random(48);
        self::saveMeta($d, ['bg_token' => hash('sha256', $tok), 'bg_issued' => now()->toIso8601String()]);

        return ['path' => 'travo/bgloc/' . $d->public_id . '/' . $tok];
    }

    public static function bgRevoke(Driver $d): void
    {
        self::saveMeta($d, ['bg_token' => null]);
    }

    /** POST from Median: {locations: [{latitude, longitude, speed, bearing, accuracy, timestamp, ...}]} */
    public static function bgIngest(string $driverId, string $token, array $payload): array
    {
        $d = self::find($driverId);
        if (!$d || $d->status !== 'active') {
            return ['error' => 'unknown driver', 'code' => 404];
        }
        $h = data_get(self::meta($d), 'travo.bg_token');
        if (!$h || !hash_equals($h, hash('sha256', $token))) {
            return ['error' => 'unauthorised', 'code' => 401];
        }
        if (!$d->online) {
            return ['ok' => true, 'ignored' => 'offline'];   // they switched off in the app; Median will be stopped
        }
        $raw = $payload['locations'] ?? (isset($payload['location']) ? [$payload['location']] : (array_is_list($payload) ? $payload : [$payload]));
        $pts = [];
        foreach (array_slice((array) $raw, -300) as $l) {
            $l   = (array) $l;
            $c   = (array) ($l['coords'] ?? $l);
            $lat = $c['latitude'] ?? $c['lat'] ?? null;
            $lng = $c['longitude'] ?? $c['lng'] ?? $c['lon'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180 || (abs((float) $lat) < 0.0001 && abs((float) $lng) < 0.0001)) {
                continue;
            }
            $ts = $l['timestamp'] ?? $l['time'] ?? null;
            $at = is_numeric($ts) ? Carbon::createFromTimestampMs((float) $ts > 1e11 ? (float) $ts : (float) $ts * 1000) : ($ts ? rescue(fn () => Carbon::parse($ts), null, false) : null);
            $at = ($at && $at->lte(now()->addMinutes(2)) && $at->gte(now()->subDay())) ? $at : now();
            $num = fn ($k) => isset($c[$k]) && is_numeric($c[$k]) ? (float) $c[$k] : (isset($l[$k]) && is_numeric($l[$k]) ? (float) $l[$k] : null);
            $pts[] = ['lat' => (float) $lat, 'lng' => (float) $lng, 'at' => $at, 'speed' => $num('speed'),
                'heading' => $num('bearing') ?? $num('heading') ?? $num('course'), 'acc' => $num('accuracy') ?? $num('horizontalAccuracy')];
        }
        if (!$pts) {
            return ['ok' => true, 'saved' => 0];
        }
        usort($pts, fn ($a, $b) => $a['at'] <=> $b['at']);
        $last = array_pop($pts);
        // earlier points in a batch (e.g. sent together after a signal gap) go straight into the route history
        foreach ($pts as $p) {
            rescue(function () use ($d, $p) {
                $pos = new \Fleetbase\FleetOps\Models\Position();
                $pos->forceFill(['company_uuid' => $d->company_uuid, 'subject_uuid' => $d->uuid, 'subject_type' => Driver::class,
                    'coordinates' => new Point($p['lat'], $p['lng']), 'heading' => $p['heading'], 'speed' => $p['speed']]);
                $pos->created_at = $p['at'];
                $pos->updated_at = $p['at'];
                $pos->save();
            }, null, false);
        }
        DB::table('drivers')->where('uuid', $d->uuid)->update(['updated_at' => now()->subSeconds((int) self::settings()['min_track_seconds'] + 1)]);
        $d->refresh();
        self::track($d, $last['lat'], $last['lng'], $last['heading'], $last['speed'], $last['acc']);
        self::saveMeta($d, ['bg_last' => now()->toIso8601String()]);

        return ['ok' => true, 'saved' => count($pts) + 1];
    }

    /** Orders and quotes created in the last N hours (all customers), newest first, for quick assignment. */
    public static function recentOrders(int $hours = 24): array
    {
        $orders = Order::where('created_at', '>', now()->subHours(max(1, min(168, $hours))))->whereNull('deleted_at')
            ->where('status', '!=', 'canceled')->orderByDesc('created_at')->limit(60)->get();
        $drivers = Driver::whereIn('uuid', $orders->pluck('driver_assigned_uuid')->filter()->unique())->get(['uuid', 'public_id', 'user_uuid'])->keyBy('uuid');
        $names   = DB::table('users')->whereIn('uuid', $drivers->pluck('user_uuid')->merge($orders->pluck('created_by_uuid'))->merge($orders->pluck('customer_uuid'))->filter()->unique())
            ->pluck('name', 'uuid');
        $out = [];
        foreach ($orders as $o) {
            $v = rescue(fn () => self::orderView($o, false), null, false);
            if (!$v || $v['canceled']) {
                continue;
            }
            $drv = $o->driver_assigned_uuid ? ($drivers[$o->driver_assigned_uuid] ?? null) : null;
            $out[] = [
                'id' => $v['id'], 'ref' => $v['ref'], 'service' => $v['service'], 'next' => $v['next'], 'paid' => $v['paid'], 'done' => $v['done'],
                'pickup' => $v['pickup']['label'], 'dropoff' => $v['dropoff']['label'], 'when' => $v['when'],
                'created'  => optional($o->created_at)->toIso8601String(),
                'customer' => $names[$o->created_by_uuid] ?? ($names[$o->customer_uuid] ?? null),
                'driver'   => $drv ? ['id' => $drv->public_id, 'name' => $names[$drv->user_uuid] ?? 'Driver'] : null,
            ];
        }

        return ['orders' => $out, 'hours' => $hours];
    }

    /** Live tracking view for staff: details, current position, recent route and open jobs. */
    public static function live(string $id, int $hours = 3): array
    {
        $d = self::find($id);
        if (!$d) {
            return ['error' => 'Driver not found'];
        }
        $u   = DB::table('users')->where('uuid', $d->user_uuid)->first(['name', 'email', 'phone']);
        $t   = (array) (self::meta($d)['travo'] ?? []);
        $v   = $d->vehicle_uuid ? DB::table('vehicles')->where('uuid', $d->vehicle_uuid)->first(['make', 'model', 'year', 'plate_number', 'meta']) : null;
        $vm  = $v ? (array) data_get(json_decode((string) $v->meta, true), 'travo', []) : [];
        $loc = $d->location;
        $at  = ($loc && abs($loc->getLat()) > 0.0001) ? ['lat' => $loc->getLat(), 'lng' => $loc->getLng()] : null;
        $trail = \Fleetbase\FleetOps\Models\Position::where('subject_uuid', $d->uuid)->where('created_at', '>', now()->subHours(max(1, min(24, $hours))))
            ->orderByDesc('id')->limit(600)->get(['coordinates', 'speed', 'created_at'])->reverse()
            ->map(fn ($p) => $p->coordinates ? [round($p->coordinates->getLat(), 6), round($p->coordinates->getLng(), 6), Carbon::parse($p->created_at)->toIso8601String()] : null)
            ->filter()->values()->all();
        $selfie = DB::table('files')->where('folder', 'travo-driver-docs')->where('subject_uuid', $d->user_uuid)->where('type', 'selfie')->whereNull('deleted_at')->value('uuid');
        $jobs   = Order::where('driver_assigned_uuid', $d->uuid)->whereNull('deleted_at')->whereNotIn('status', ['completed', 'canceled'])->orderByDesc('created_at')->limit(5)->get()
            ->map(fn ($o) => self::orderView($o, true))->values()->all();
        $type = $t['vehicle'] ?? ($vm['type'] ?? null);

        return [
            'id'       => $d->public_id,
            'name'     => optional($u)->name ?: 'Driver',
            'phone'    => ($t['phone'] ?? null) ?: optional($u)->phone,
            'email'    => optional($u)->email,
            'photo'    => $selfie,
            'status'   => $d->status === 'active' ? 'approved' : $d->status,
            'online'   => self::isOnline($d),
            'at'       => $at,
            'heading'  => is_numeric($d->heading) ? (float) $d->heading : null,
            'speed'    => is_numeric($d->speed) ? (float) $d->speed : null,
            'accuracy' => $t['accuracy'] ?? null,
            'lastSeen' => optional($d->updated_at)->toIso8601String(),
            'lastFix'  => $t['last_fix'] ?? null,
            'vehicle'  => [
                'type'   => $type, 'label' => self::VEHICLES[$type ?? ''] ?? null, 'icon' => self::vehicleIcon($type),
                'name'   => $v ? trim(implode(' ', array_filter([$v->year, $v->make, $v->model]))) : null,
                'colour' => $vm['colour'] ?? null, 'plate' => $v->plate_number ?? ($t['plate'] ?? null),
            ],
            'area'     => ($t['area'] ?? null) ?: $d->city,
            'jobs'     => $jobs,
            'trail'    => $trail,
            'timeoutMin' => (int) self::settings()['online_timeout_min'],
        ];
    }

    public static function review(string $id, bool $approve, ?string $note = null): array
    {
        $d = self::find($id);
        if (!$d) {
            return ['error' => 'Driver not found'];
        }
        $d->status = $approve ? 'active' : 'rejected';
        $d->save();
        self::saveMeta($d, ['reviewed_at' => now()->toIso8601String(), 'review_note' => $note ? Str::limit($note, 300, '') : null]);
        $u = DB::table('users')->where('uuid', $d->user_uuid)->first(['name', 'email']);
        if ($u && $u->email) {
            $first = trim(explode(' ', trim((string) $u->name))[0] ?? '');
            $approve
                ? self::mail($u->email, 'You\'re approved to drive with Travo', 'Welcome to Travo Drivers' . ($first ? ', ' . $first : '') . '!',
                    ['Your driver account has been approved.', 'Open Travo, tap Drive with Travo and switch on Go online when you\'re ready for jobs. Keep the Travo page open while you\'re online so we can see where you are and send you nearby jobs.',
                     'When a job is assigned to you, you\'ll see the pickup, drop-off and customer details there. Mark it Picked up when you collect it and Delivered (with a photo) when you drop it off.'],
                    'https://travo.ng/#drive', 'Open Travo Driver')
                : self::mail($u->email, 'Your Travo driver application', 'About your driver application',
                    ['Thank you for applying to drive with Travo. We can\'t approve your application at the moment.' . ($note ? ' ' . $note : ''), 'If your details change, you can update them and apply again from Drive with Travo.'],
                    'https://travo.ng/#drive', 'Open Drive with Travo');
        }

        return ['ok' => true];
    }

    public static function assign(string $orderId, ?string $driverId): array
    {
        $o = self::findOrder($orderId);
        if (!$o) {
            return ['error' => 'Order not found'];
        }
        if (!$driverId) {
            $o->driver_assigned_uuid = null;
            $o->save();

            return ['ok' => true, 'driver' => null];
        }
        $d = self::find($driverId);
        if (!$d || $d->status !== 'active') {
            return ['error' => 'Choose an approved driver.'];
        }
        $o->driver_assigned_uuid = $d->uuid;
        $o->save();
        TravoJobOffers::close($o->uuid, 'withdrawn');
        rescue(fn () => $o->notifyDriverAssigned(), null, false);
        $u = DB::table('users')->where('uuid', $d->user_uuid)->first(['name', 'email']);
        $v = self::orderView($o, true);
        if ($u && $u->email) {
            self::mail($u->email, 'New Travo job: ' . $v['ref'], 'You have a new job',
                [$v['service'] . ' · ' . $v['ref'], 'Pickup: ' . ($v['pickup']['label'] ?: 'see the app'), 'Drop-off: ' . ($v['dropoff']['label'] ?: 'see the app'),
                 $v['paid'] ? 'Payment has been received, so it\'s ready to collect.' : 'Payment hasn\'t been received yet. Please don\'t collect it until the app shows it as Paid.'],
                'https://travo.ng/#drive', 'Open the job');
        }

        return ['ok' => true, 'driver' => ['id' => $d->public_id, 'name' => optional($u)->name]];
    }

    /** Assigned driver + proofs for the admin order page. */
    public static function orderDriver(string $orderId): array
    {
        $o = self::findOrder($orderId);
        if (!$o) {
            return ['error' => 'Order not found'];
        }
        $d = $o->driver_assigned_uuid ? Driver::where('uuid', $o->driver_assigned_uuid)->first() : null;
        $u = $d ? DB::table('users')->where('uuid', $d->user_uuid)->first(['name', 'phone']) : null;
        $proofs = DB::table('proofs as p')->leftJoin('files as f', 'f.uuid', '=', 'p.file_uuid')->where('p.order_uuid', $o->uuid)->whereNull('p.deleted_at')
            ->orderBy('p.id')->get(['p.public_id', 'p.remarks', 'p.data', 'p.created_at', 'f.uuid as file']);

        $pick = rescue(fn () => optional($o->payload->pickup)->location, null, false);
        $km   = function ($at) use ($pick) {
            if (!$pick || !$at) {
                return null;
            }
            $r = M_PI / 180;
            $x = ($at['lng'] - $pick->getLng()) * $r * cos(($at['lat'] + $pick->getLat()) / 2 * $r);
            $y = ($at['lat'] - $pick->getLat()) * $r;

            return round(sqrt($x * $x + $y * $y) * 6371, 1);
        };

        return [
            'offers' => TravoJobOffers::forOrder($o->uuid),
            'paid'   => self::orderView($o, false)['paid'],
            'driver' => $d ? ['id' => $d->public_id, 'name' => optional($u)->name, 'phone' => optional($u)->phone, 'online' => self::isOnline($d),
                'at' => ($d->location && abs($d->location->getLat()) > 0.0001) ? ['lat' => $d->location->getLat(), 'lng' => $d->location->getLng()] : null] : null,
            'proofs' => $proofs->map(fn ($p) => ['id' => $p->public_id, 'remarks' => $p->remarks, 'step' => data_get(json_decode((string) $p->data, true), 'step'),
                'at' => Carbon::parse($p->created_at)->toIso8601String(), 'file' => $p->file])->values()->all(),
            'drivers' => collect(self::adminList())->where('status', 'approved')->map(fn ($x) => ['id' => $x['id'], 'name' => $x['name'], 'vehicle' => $x['vehicle'], 'online' => $x['online'], 'jobs' => count($x['jobs']), 'km' => $x['online'] ? $km($x['at']) : null])->values()->all(),
        ];
    }

    /** May this user see the proof file? Staff, the order's owner, or its driver. */
    public static function canSeeProof(object $user, string $fileUuid): ?object
    {
        $f = DB::table('files')->where('uuid', $fileUuid)->where('folder', 'travo-pod')->first();
        if (!$f) {
            return null;
        }
        if (TravoChat::isStaff($user)) {
            return $f;
        }
        $o = Order::where('uuid', $f->subject_uuid)->first();
        if (!$o) {
            return null;
        }
        $d = self::driverFor($user);
        if (in_array($user->uuid, [$o->created_by_uuid, $o->customer_uuid], true) || ($d && $o->driver_assigned_uuid === $d->uuid)) {
            return $f;
        }

        return null;
    }

    // ---- email ----------------------------------------------------------------------------------

    public static function mail(string $to, string $subject, string $heading, array $paras, ?string $url = null, ?string $button = null): bool
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $body = '<!doctype html><html><body style="margin:0;background:#eef2f7;font-family:Helvetica,Arial,sans-serif;color:#0f172a">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 10px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:18px;overflow:hidden;border:1px solid #e3e8ef">'
            . '<tr><td style="background:#0b1220;padding:20px 32px"><img src="https://services.travo.ng/wp-content/uploads/2025/04/blog-logo.png" alt="Travo" height="28" style="display:block;height:28px;border:0;color:#fff"></td></tr>'
            . '<tr><td style="padding:30px 32px 26px"><h1 style="margin:0 0 16px;font-size:22px;line-height:1.3">' . $e($heading) . '</h1>';
        foreach ($paras as $p) {
            $body .= '<p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#334155">' . $e($p) . '</p>';
        }
        if ($url && $button) {
            $body .= '<p style="margin:20px 0 0"><a href="' . $e($url) . '" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-weight:bold;padding:13px 22px;border-radius:10px">' . $e($button) . '</a></p>';
        }
        $body .= '</td></tr><tr><td style="background:#f6f8fb;padding:16px 32px;border-top:1px solid #e2e8f0;font-size:12px;color:#7b8798">Travo Services Limited, Lagos, Nigeria</td></tr></table></td></tr></table></body></html>';

        return (bool) rescue(function () use ($to, $subject, $body) {
            Mail::html($body, function ($m) use ($to, $subject) {
                $m->to($to)->subject($subject)->replyTo('hello@travoservices.com', 'Travo');
                if ($rp = TravoBounce::returnPath()) {
                    $m->returnPath($rp);
                }
            });

            return true;
        }, false, true);
    }
}
