<?php

namespace App\Http\Controllers;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Travo: public, shareable order tracking pages.
 *
 *   GET /share/track/{code}                   public page (Apache serves it as https://travo.ng/track/{code})
 *   GET /share/track/{code}/file/{file}       a photo attached to that order (streamed, never a bucket URL)
 *   GET /int/v1/travo/orders/{id}/track-link  signed-in console users: returns the link for an order
 *
 * Same signed-link scheme as the quote pages ("<order public_id>-<12-char HMAC>"), with a different
 * HMAC context, so a quote link can't be turned into a tracking link and vice versa.
 *
 * Shows: progress (Quoted -> Payment received -> Dispatched -> Delivered), every activity entry with
 * time and town, service, items, area-level route + map, distance, schedule, driver first name and
 * vehicle while it is on its way, payment details until payment is received, and the photos
 * uploaded on the order (including proof-of-delivery photos). PDFs and other documents are NOT shown or
 * served here; staff see them in the signed-in console.
 * Does not show: names (other than the driver's first name), phone numbers, emails or street addresses.
 */
class OrderTrackController extends Controller
{
    public const PUBLIC_BASE = 'https://travo.ng/track/';

    private const TZ = 'Africa/Lagos';

    /** Only photos are shown/served on the public page (no SVG: it can carry scripts). */
    private const PHOTO_TYPES = ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp'];

    private const PHOTO_EXT = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    public static function code(string $publicId): string
    {
        $mac = hash_hmac('sha256', 'travo-track|' . $publicId, (string) config('app.key'), true);

        return $publicId . '-' . substr(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), 0, 12);
    }

    public static function url(string $publicId): string
    {
        return self::PUBLIC_BASE . self::code($publicId);
    }

    /** Signed-in users: the tracking link for an order (any signed-in user who can see the order). */
    public function link(Request $request, string $id)
    {
        $user  = $request->user();
        $order = Order::where('public_id', $id)->first() ?? Order::where('uuid', $id)->first();
        if (!$user || !$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }
        $allowed = $user->company_uuid === $order->company_uuid
            || (method_exists($user, 'isAdmin') && $user->isAdmin())
            || DB::table('company_users')->where('user_uuid', $user->uuid)->where('company_uuid', $order->company_uuid)->exists();
        if (!$allowed) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json(['url' => self::url($order->public_id)]);
    }

    /** Public tracking page. */
    public function show(string $code)
    {
        $order = $this->orderFromCode($code);
        if (!$order) {
            return $this->respond(response()->view('order-track-missing', [], 404));
        }

        try {
            $data = $this->build($order, $code);
        } catch (\Throwable $e) {
            Log::error('Travo tracking page failed: ' . $e->getMessage(), ['order' => $order->public_id]);

            return $this->respond(response()->view('order-track-missing', ['broken' => true], 500));
        }

        return $this->respond(response()->view('order-track', $data));
    }

    /** A file attached to the order (only files that belong to this order are served). */
    public function file(string $code, string $file)
    {
        $order = $this->orderFromCode($code);
        if (!$order) {
            abort(404);
        }
        $row = collect($this->files($order))->firstWhere('id', $file);
        if (!$row) {
            abort(404);
        }
        try {
            $disk = Storage::disk($row['disk'] ?: config('filesystems.default'));
            if (!$disk->exists($row['path'])) {
                abort(404);
            }
            $type   = $row['type'] ?: ($disk->mimeType($row['path']) ?: 'application/octet-stream');
            $inline = Str::startsWith($type, 'image/') || $type === 'application/pdf';
            $name   = preg_replace('/[^\w.\- ]+/u', '_', $row['name']) ?: 'file';

            return response()->stream(function () use ($disk, $row) {
                $in = $disk->readStream($row['path']);
                fpassthru($in);
                if (is_resource($in)) {
                    fclose($in);
                }
            }, 200, [
                'Content-Type'           => $type,
                'Content-Disposition'    => ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"',
                'Cache-Control'          => 'private, max-age=3600',
                'X-Robots-Tag'           => 'noindex, nofollow',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Travo tracking file failed: ' . $e->getMessage(), ['order' => $order->public_id, 'file' => $file]);
            abort(404);
        }
    }

    // ---------------------------------------------------------------------------------------

    private function orderFromCode(string $code): ?Order
    {
        // the signature is always 12 characters and may itself contain "-", so split by length
        $publicId = strlen($code) > 13 && $code[strlen($code) - 13] === '-' ? substr($code, 0, -13) : '';
        if (!$publicId || !hash_equals(self::code($publicId), $code)) {
            return null;
        }

        return Order::where('public_id', $publicId)
            ->with(['payload.pickup', 'payload.dropoff', 'payload.entities', 'purchaseRate.serviceQuote.items'])
            ->first();
    }

    private function respond($response)
    {
        return $response
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'private, no-cache');
    }

    private function build(Order $order, string $code): array
    {
        $q     = app(QuoteShareController::class)->buildQuote($order, QuoteShareController::code($order->public_id));
        $state = OrderFlowController::state($order);
        $ref   = OrderFlowController::paymentRef($order);

        $steps = [];
        foreach ($state['steps'] as $s) {
            $steps[] = ['key' => $s['key'], 'label' => $s['label'], 'done' => $s['done'], 'at' => $this->when($s['at'] ?? null, true)];
        }
        $headline = [
            'quoted'     => ['Awaiting payment', 'Your quote is ready. Pay using the details below to confirm the booking.'],
            'paid'       => ['Booking confirmed', 'Payment received. We are getting your order ready for dispatch.'],
            'dispatched' => ['On its way', 'Your order has been dispatched or collected and is on its way.'],
            'delivered'  => ['Delivered', 'Your order has been delivered.'],
        ][$state['current']];
        if ($state['canceled']) {
            $headline = ['Canceled', 'This order was canceled. Contact us on WhatsApp if this is unexpected.'];
        }
        $paid    = $state['current'] !== 'quoted';
        $showPay = !$paid && !$state['canceled'];

        // driver + vehicle while it is on its way (first name only)
        $driver  = null;
        $vehicle = null;
        if (in_array($state['current'], ['dispatched', 'delivered'], true) && !$state['canceled']) {
            $driverName = rescue(fn () => optional($order->driverAssigned)->name, null, false);
            $driver     = $driverName ? trim(explode(' ', trim($driverName))[0]) : null;
            if ($order->vehicle_assigned_uuid) {
                $v = DB::table('vehicles')->where('uuid', $order->vehicle_assigned_uuid)->first();
                if ($v) {
                    $vehicle = trim(implode(' ', array_filter([$v->year ?? null, $v->make ?? null, $v->model ?? null]))) ?: null;
                }
            }
        }

        // activity log: everything recorded against the order's tracking number, newest first
        $activity = [];
        if ($order->tracking_number_uuid) {
            $rows = DB::table('tracking_statuses')
                ->where('tracking_number_uuid', $order->tracking_number_uuid)
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get(['status', 'details', 'code', 'city', 'province', 'created_at']);
            foreach ($rows as $r) {
                $where = array_values(array_unique(array_filter([$this->clean($r->city), $this->clean($r->province)])));
                $status = $this->clean($r->status) ?: Str::headline(strtolower((string) $r->code));
                $details = $this->clean($r->details);
                $activity[] = [
                    'status'  => $status,
                    'details' => strcasecmp($details, $status) === 0 ? '' : $details,
                    'where'   => implode(', ', $where),
                    'at'      => $this->when($r->created_at),
                    'code'    => strtoupper((string) $r->code),
                ];
            }
        }
        if (!$activity) {
            $activity[] = ['status' => 'Order created', 'details' => '', 'where' => '', 'at' => $this->when($order->created_at), 'code' => 'CREATED'];
        }

        // photos (documents are not shown on the public page)
        $images = [];
        foreach ($this->files($order) as $f) {
            $images[] = [
                'url'   => self::PUBLIC_BASE . $code . '/file/' . $f['id'],
                'name'  => $f['label'],
                'at'    => $this->when($f['at']),
                'proof' => $f['proof'],
            ];
        }

        $trackingNo = optional($order->trackingNumber)->tracking_number;
        $updated    = $activity[0]['at'] ?? null;

        return [
            'url'        => self::PUBLIC_BASE . $code,
            'quoteUrl'   => $q['url'],
            'ref'        => $ref,
            'trackingNo' => $trackingNo,
            'orderType'  => $q['orderType'],
            'service'    => $q['service'],
            'headline'   => $headline[0],
            'sub'        => $headline[1],
            'canceled'   => $state['canceled'],
            'current'    => $state['current'],
            'steps'      => $steps,
            'updated'    => $updated,
            'created'    => $this->when($order->created_at),
            'scheduled'  => $order->scheduled_at ? $this->when($order->scheduled_at) : $q['scheduled'],
            'pickup'     => $q['pickup'],
            'dropoff'    => $q['dropoff'],
            'distanceKm' => $q['distanceKm'],
            'minutes'    => $q['minutes'],
            'items'      => $q['items'],
            'map'        => $q['map'],
            'price'      => $q['price'],
            'lines'      => $q['lines'],
            'showPay'    => $showPay,
            'paidNote'   => $paid && !$state['canceled'],
            'bank'       => OrderFlowController::BANK,
            'whatsapp'   => OrderFlowController::WHATSAPP,
            'driver'     => $driver,
            'vehicle'    => $vehicle,
            'activity'   => $activity,
            'images'     => $images,
        ];
    }

    /**
     * Files that belong to the order: uploads on the order itself, on its payload items,
     * and proof-of-delivery files. Each row: id, disk, path, name, label, type, size, at, proof.
     */
    private function files(Order $order): array
    {
        $subjects = array_values(array_filter([$order->uuid, $order->payload_uuid]));
        foreach (optional($order->payload)->entities ?? [] as $e) {
            $subjects[] = $e->uuid;
        }
        $proofFiles = DB::table('proofs')->where('order_uuid', $order->uuid)->whereNull('deleted_at')->whereNotNull('file_uuid')->pluck('file_uuid')->all();

        $rows = DB::table('files')
            ->whereNull('deleted_at')
            ->where(function ($w) use ($subjects, $proofFiles) {
                $w->whereIn('subject_uuid', $subjects);
                if ($proofFiles) {
                    $w->orWhereIn('uuid', $proofFiles);
                }
            })
            ->orderBy('created_at')
            ->limit(60)
            ->get(['uuid', 'public_id', 'disk', 'path', 'original_filename', 'caption', 'content_type', 'file_size', 'created_at']);

        $out = [];
        foreach ($rows as $r) {
            if (!$r->path) {
                continue;
            }
            $name  = (string) ($r->original_filename ?: basename((string) $r->path));
            // photos only: PDFs and other documents stay in the signed-in console (decided 26 Oct)
            $type = strtolower((string) $r->content_type);
            $ext  = strtolower(pathinfo($name ?: (string) $r->path, PATHINFO_EXTENSION));
            if (!in_array($type, self::PHOTO_TYPES, true) && !($type === '' || $type === 'application/octet-stream' ? isset(self::PHOTO_EXT[$ext]) : false)) {
                continue;
            }
            if ($type === '' || $type === 'application/octet-stream') {
                $type = self::PHOTO_EXT[$ext];
            }
            $proof = in_array($r->uuid, $proofFiles, true);
            $label = $this->clean($r->caption) ?: ($proof ? 'Proof of delivery' : 'Photo ' . (count($out) + 1));
            $out[] = [
                'id'    => $r->public_id ?: $r->uuid,
                'disk'  => $r->disk,
                'path'  => $r->path,
                'name'  => $name,
                'label' => Str::limit($label ?: 'Attachment', 60),
                'type'  => $type,
                'size'  => (int) $r->file_size,
                'at'    => $r->created_at,
                'proof' => $proof,
            ];
        }

        return $out;
    }

    private function when($t, bool $short = false): ?string
    {
        if (!$t) {
            return null;
        }
        try {
            return Carbon::parse($t)->timezone(self::TZ)->format($short ? 'j M, g:ia' : 'D j M Y, g:ia');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** Removes anything that looks like a phone number or email from free text. */
    private function clean($text): string
    {
        $text = (string) $text;
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '', $text);
        $text = preg_replace('/\+?\d[\d\s().-]{6,}\d/u', '', $text);

        return trim(preg_replace('/\s{2,}/', ' ', $text), " \t\n\r\0\x0B,-·");
    }
}
