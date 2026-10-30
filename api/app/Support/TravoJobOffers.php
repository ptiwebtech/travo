<?php

namespace App\Support;

use App\Http\Controllers\OrderFlowController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Job offers (28 Oct 2026): staff offer an order to one or more approved drivers from the order page; each gets an
 * email and sees the offer in Drive with Travo (Accept / Decline). The first to accept gets the job (row-locked),
 * the others' offers close as "taken". Offers expire after N minutes (default 30). Assigning a driver directly
 * withdraws any open offers.
 */
class TravoJobOffers
{
    public const TABLE = 'travo_job_offers';

    public static function ensure(): void
    {
        static $done = false;
        if ($done || Schema::hasTable(self::TABLE)) {
            $done = true;

            return;
        }
        Schema::create(self::TABLE, function (Blueprint $t) {
            $t->increments('id');
            $t->uuid('uuid')->unique();
            $t->uuid('order_uuid')->index();
            $t->uuid('driver_uuid')->index();
            $t->string('status', 20)->default('offered');   // offered | accepted | declined | taken | withdrawn  (expired = offered past expires_at)
            $t->uuid('offered_by_uuid')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('responded_at')->nullable();
            $t->timestamps();
        });
        $done = true;
    }

    private static function state(object $r): string
    {
        return $r->status === 'offered' && $r->expires_at && Carbon::parse($r->expires_at)->isPast() ? 'expired' : $r->status;
    }

    public static function offer(string $orderId, array $driverIds, int $minutes, object $by): array
    {
        self::ensure();
        $o = TravoDriver::findOrder($orderId);
        if (!$o) {
            return ['error' => 'Order not found'];
        }
        $st = OrderFlowController::state($o);
        if (($st['canceled'] ?? false) || in_array($o->status, ['completed', 'canceled'], true)) {
            return ['error' => 'This order is closed.'];
        }
        if ($o->driver_assigned_uuid) {
            return ['error' => 'This order already has a driver. Remove them first to offer it again.'];
        }
        $minutes = max(5, min(240, $minutes ?: 30));
        $view    = TravoDriver::orderView($o, false);
        $exp     = now()->addMinutes($minutes);
        $sent    = [];
        foreach (array_unique(array_filter($driverIds)) as $id) {
            $d = TravoDriver::find((string) $id);
            if (!$d || $d->status !== 'active') {
                continue;
            }
            $open = DB::table(self::TABLE)->where('order_uuid', $o->uuid)->where('driver_uuid', $d->uuid)->where('status', 'offered')->where('expires_at', '>', now())->exists();
            if ($open) {
                continue;
            }
            DB::table(self::TABLE)->insert(['uuid' => (string) Str::uuid(), 'order_uuid' => $o->uuid, 'driver_uuid' => $d->uuid, 'status' => 'offered',
                'offered_by_uuid' => $by->uuid, 'expires_at' => $exp, 'created_at' => now(), 'updated_at' => now()]);
            $u = DB::table('users')->where('uuid', $d->user_uuid)->first(['name', 'email']);
            if ($u && $u->email) {
                TravoDriver::mail($u->email, 'Job offer: ' . $view['service'] . ' · ' . $view['ref'], 'New job offer',
                    [$view['service'] . ' · ' . $view['ref'] . ($view['when'] ? ' · ' . Carbon::parse($view['when'])->timezone('Africa/Lagos')->format('D j M, g:ia') : ''),
                     'Pickup: ' . ($view['pickup']['label'] ?: 'see the app'), 'Drop-off: ' . ($view['dropoff']['label'] ?: 'see the app'),
                     'Open Travo and tap Accept before ' . $exp->copy()->timezone('Africa/Lagos')->format('g:ia') . '. The first driver to accept gets the job.'],
                    'https://travo.ng/#drive', 'See the offer');
            }
            $sent[] = $d->public_id;
        }
        if (!$sent) {
            return ['error' => 'Choose at least one approved driver who doesn\'t already have this offer.'];
        }

        return ['ok' => true, 'sent' => count($sent), 'expires' => $exp->toIso8601String()];
    }

    /** Close open offers for an order (e.g. assigned directly, or the admin withdraws them). */
    public static function close(string $orderUuid, string $as = 'withdrawn', ?string $exceptDriver = null): void
    {
        self::ensure();
        DB::table(self::TABLE)->where('order_uuid', $orderUuid)->where('status', 'offered')
            ->when($exceptDriver, fn ($q) => $q->where('driver_uuid', '!=', $exceptDriver))
            ->update(['status' => $as, 'updated_at' => now()]);
    }

    public static function forOrder(string $orderUuid): array
    {
        self::ensure();
        $rows = DB::table(self::TABLE . ' as x')->join('drivers as d', 'd.uuid', '=', 'x.driver_uuid')->leftJoin('users as u', 'u.uuid', '=', 'd.user_uuid')
            ->where('x.order_uuid', $orderUuid)->orderByDesc('x.id')->limit(30)
            ->get(['x.uuid', 'x.status', 'x.expires_at', 'x.responded_at', 'x.created_at', 'd.public_id', 'u.name']);

        return $rows->map(fn ($r) => ['id' => $r->uuid, 'driver' => $r->public_id, 'name' => $r->name ?: 'Driver', 'status' => self::state($r),
            'at' => Carbon::parse($r->created_at)->toIso8601String(), 'expires' => $r->expires_at ? Carbon::parse($r->expires_at)->toIso8601String() : null])->values()->all();
    }

    /** Open offers for a driver. */
    public static function forDriver(Driver $d): array
    {
        self::ensure();
        if ($d->status !== 'active') {
            return [];
        }
        $rows = DB::table(self::TABLE)->where('driver_uuid', $d->uuid)->where('status', 'offered')->where('expires_at', '>', now())->orderBy('expires_at')->limit(10)->get();
        $out  = [];
        foreach ($rows as $r) {
            $o = Order::where('uuid', $r->order_uuid)->whereNull('deleted_at')->first();
            if (!$o || $o->driver_assigned_uuid || in_array($o->status, ['completed', 'canceled'], true)) {
                DB::table(self::TABLE)->where('id', $r->id)->update(['status' => 'taken', 'updated_at' => now()]);
                continue;
            }
            $out[] = ['offer' => $r->uuid, 'expires' => Carbon::parse($r->expires_at)->toIso8601String()] + TravoDriver::orderView($o, false);
        }

        return $out;
    }

    public static function respond(object $user, Driver $d, string $offerUuid, bool $accept): array
    {
        self::ensure();
        if ($d->status !== 'active') {
            return ['error' => 'Your driver account is not approved yet.'];
        }
        $res = DB::transaction(function () use ($d, $offerUuid, $accept) {
            $r = DB::table(self::TABLE)->where('uuid', $offerUuid)->where('driver_uuid', $d->uuid)->lockForUpdate()->first();
            if (!$r) {
                return ['error' => 'Offer not found.'];
            }
            $state = self::state($r);
            if ($state !== 'offered') {
                return ['error' => $state === 'expired' ? 'Sorry, this offer has expired.' : ($state === 'taken' ? 'Sorry, another driver has already taken this job.' : 'This offer is no longer open.')];
            }
            if (!$accept) {
                DB::table(self::TABLE)->where('id', $r->id)->update(['status' => 'declined', 'responded_at' => now(), 'updated_at' => now()]);

                return ['ok' => true, 'declined' => true];
            }
            $o = Order::where('uuid', $r->order_uuid)->lockForUpdate()->first();
            if (!$o || in_array($o->status, ['completed', 'canceled'], true)) {
                DB::table(self::TABLE)->where('id', $r->id)->update(['status' => 'withdrawn', 'updated_at' => now()]);

                return ['error' => 'This order has been closed.'];
            }
            if ($o->driver_assigned_uuid && $o->driver_assigned_uuid !== $d->uuid) {
                DB::table(self::TABLE)->where('id', $r->id)->update(['status' => 'taken', 'updated_at' => now()]);

                return ['error' => 'Sorry, another driver has already taken this job.'];
            }
            $o->driver_assigned_uuid = $d->uuid;
            $o->save();
            DB::table(self::TABLE)->where('id', $r->id)->update(['status' => 'accepted', 'responded_at' => now(), 'updated_at' => now()]);
            DB::table(self::TABLE)->where('order_uuid', $o->uuid)->where('status', 'offered')->where('id', '!=', $r->id)->update(['status' => 'taken', 'updated_at' => now()]);

            return ['ok' => true, 'order' => $o];
        });
        if (empty($res['order'])) {
            return $res;
        }
        $o = $res['order'];
        rescue(fn () => $o->notifyDriverAssigned(), null, false);
        $v = TravoDriver::orderView($o->fresh(), true);
        TravoDriver::mail(TravoDriver::settings()['staff_email'], '[Travo] ' . ($user->name ?: 'A driver') . ' accepted ' . $v['ref'], 'Job accepted',
            [($user->name ?: 'A driver') . ' accepted ' . $v['service'] . ' · ' . $v['ref'] . '.', 'Pickup: ' . ($v['pickup']['label'] ?: '-'), 'Drop-off: ' . ($v['dropoff']['label'] ?: '-')]);

        return ['ok' => true, 'job' => $v];
    }
}
