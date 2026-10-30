<?php

namespace App\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Travo driver applications (28 Oct 2026).
 *
 * A driver fills in a full application (personal + NIN, licence, vehicle, guarantor, bank account) and uploads
 * documents (selfie, ID, licence, vehicle photo, vehicle papers, insurance). It autosaves as a draft; on submit a
 * Fleet-Ops driver is created in the operating company with status "pending" and staff are emailed.
 * Staff review it (Drivers admin): approve (fills in the Fleet-Ops driver + creates/links their Vehicle),
 * ask for changes (driver edits and resubmits), or decline. Only approved drivers can go online and accept jobs.
 *
 * The application lives in its own table, NOT in drivers.meta, because Fleet-Ops returns driver meta to any
 * console user of the operating company. NIN and account number are encrypted (APP_KEY).
 * Documents: files.folder = travo-driver-docs on the local disk, served only to the applicant and staff.
 */
class TravoDriverApplication
{
    public const TABLE = 'travo_driver_applications';

    public const DOCS = [
        'selfie'    => ['label' => 'A clear photo of your face', 'required' => true, 'pdf' => false],
        'id'        => ['label' => 'NIN slip or ID card', 'required' => true, 'pdf' => true],
        'licence'   => ['label' => "Driver's licence (front)", 'required' => true, 'pdf' => true],
        'vehicle'   => ['label' => 'Your vehicle, with the plate visible', 'required' => true, 'pdf' => false],
        'papers'    => ['label' => 'Vehicle papers (registration or proof of ownership)', 'required' => true, 'pdf' => true],
        'insurance' => ['label' => 'Insurance certificate (if you have one)', 'required' => false, 'pdf' => true],
    ];

    /** field => [label, required] (plain fields; nin and account_number are handled separately, encrypted) */
    public const FIELDS = [
        'full_name' => 'Full name', 'dob' => 'Date of birth', 'phone' => 'Phone number', 'address' => 'Home address',
        'state' => 'State', 'area' => 'Where you want to work',
        'licence_number' => "Driver's licence number", 'licence_expiry' => 'Licence expiry date',
        'vehicle' => 'Vehicle type', 'make' => 'Vehicle make', 'model' => 'Vehicle model', 'colour' => 'Colour', 'year' => 'Year', 'plate' => 'Plate number',
        'g_name' => "Guarantor's name", 'g_phone' => "Guarantor's phone", 'g_relationship' => 'Relationship', 'g_address' => "Guarantor's address",
        'bank_name' => 'Bank', 'account_name' => 'Account name',
    ];

    public const SECRET = ['nin' => 'NIN', 'account_number' => 'Account number'];

    public const STATES = ['Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo',
        'Ekiti', 'Enugu', 'FCT Abuja', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger',
        'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara'];

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
            $t->uuid('user_uuid')->unique();
            $t->uuid('driver_uuid')->nullable()->index();
            $t->string('status', 20)->default('draft');   // draft | submitted | changes | approved | rejected
            $t->longText('data')->nullable();
            $t->text('note')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->uuid('reviewed_by_uuid')->nullable();
            $t->timestamps();
        });
        $done = true;
    }

    public static function forUser(object $user): ?object
    {
        self::ensure();

        return DB::table(self::TABLE)->where('user_uuid', $user->uuid)->first();
    }

    public static function forDriver(Driver $d): ?object
    {
        self::ensure();

        return DB::table(self::TABLE)->where('driver_uuid', $d->uuid)->first() ?: DB::table(self::TABLE)->where('user_uuid', $d->user_uuid)->first();
    }

    private static function data(?object $row): array
    {
        $d = $row ? json_decode((string) $row->data, true) : [];

        return is_array($d) ? $d : [];
    }

    private static function dec(?string $v): ?string
    {
        return $v ? rescue(fn () => Crypt::decryptString($v), null, false) : null;
    }

    private static function mask(?string $v): ?string
    {
        return $v ? str_repeat('•', max(0, strlen($v) - 4)) . substr($v, -4) : null;
    }

    private static function docs(object $user): array
    {
        return DB::table('files')->where('folder', 'travo-driver-docs')->where('subject_uuid', $user->uuid)->whereNull('deleted_at')
            ->orderBy('id')->get(['uuid', 'type', 'original_filename', 'content_type', 'created_at'])
            ->keyBy('type')->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->original_filename, 'pdf' => $f->content_type === 'application/pdf'])->all();
    }

    /** What's still missing before the application can be submitted. */
    public static function missing(array $data, array $docs): array
    {
        $m = [];
        foreach (self::FIELDS as $k => $label) {
            if (trim((string) ($data[$k] ?? '')) === '') {
                $m[] = $label;
            }
        }
        foreach (self::SECRET as $k => $label) {
            if (empty($data[$k . '_enc'])) {
                $m[] = $label;
            }
        }
        foreach (self::DOCS as $k => $doc) {
            if ($doc['required'] && empty($docs[$k])) {
                $m[] = 'Photo: ' . $doc['label'];
            }
        }

        return $m;
    }

    /** The applicant's own view (secrets masked). */
    public static function view(object $user): ?array
    {
        $row = self::forUser($user);
        if (!$row) {
            return null;
        }
        $data = self::data($row);
        $docs = self::docs($user);
        $out  = array_intersect_key($data, self::FIELDS);
        foreach (self::SECRET as $k => $l) {
            $out[$k . '_masked'] = self::mask(self::dec($data[$k . '_enc'] ?? null));
        }

        return ['status' => $row->status, 'note' => $row->note, 'data' => $out, 'docs' => $docs, 'missing' => self::missing($data, $docs),
            'submittedAt' => $row->submitted_at ? Carbon::parse($row->submitted_at)->toIso8601String() : null, 'agreed' => !empty($data['agreed_at'])];
    }

    private static function row(object $user): object
    {
        self::ensure();
        $row = self::forUser($user);
        if (!$row) {
            DB::table(self::TABLE)->insert(['uuid' => (string) Str::uuid(), 'user_uuid' => $user->uuid, 'status' => 'draft',
                'data' => json_encode(['full_name' => $user->name ?? '', 'phone' => $user->phone ?? '']), 'created_at' => now(), 'updated_at' => now()]);
            $row = self::forUser($user);
        }

        return $row;
    }

    /** Clean one field value. Returns [value, error]. */
    private static function clean(string $k, $v): array
    {
        $v = trim(preg_replace('/\s+/', ' ', (string) $v));
        if ($v === '') {
            return ['', null];
        }
        switch ($k) {
            case 'dob':
                $dt = rescue(fn () => Carbon::parse($v), null, false);
                if (!$dt || $dt->gt(now()->subYears(18)) || $dt->lt(now()->subYears(80))) {
                    return [null, 'You must be at least 18 to drive with Travo. Check your date of birth.'];
                }

                return [$dt->toDateString(), null];
            case 'licence_expiry':
                $dt = rescue(fn () => Carbon::parse($v), null, false);
                if (!$dt) {
                    return [null, 'Enter your licence expiry date.'];
                }
                if ($dt->lt(now()->startOfDay())) {
                    return [null, 'Your driver\'s licence has expired. Please renew it before applying.'];
                }

                return [$dt->toDateString(), null];
            case 'year':
                $y = (int) $v;

                return ($y >= 1975 && $y <= (int) now()->year + 1) ? [(string) $y, null] : [null, 'Enter the year your vehicle was made (e.g. 2015).'];
            case 'phone':
            case 'g_phone':
                return strlen(preg_replace('/\D/', '', $v)) >= 10 ? [Str::limit($v, 30, ''), null] : [null, ($k === 'phone' ? 'Your' : 'The guarantor\'s') . ' phone number looks too short.'];
            case 'vehicle':
                return isset(TravoDriver::VEHICLES[$v]) ? [$v, null] : [null, 'Choose your vehicle type.'];
            case 'state':
                return in_array($v, self::STATES, true) ? [$v, null] : [null, 'Choose your state.'];
            case 'plate':
                return [Str::upper(Str::limit($v, 20, '')), null];
            default:
                return [Str::limit($v, in_array($k, ['address', 'g_address'], true) ? 250 : 120, ''), null];
        }
    }

    /** Save the draft (any subset of fields) and optionally submit. */
    public static function save(object $user, array $in, bool $submit): array
    {
        $row = self::row($user);
        if (in_array($row->status, ['submitted', 'approved'], true)) {
            return ['error' => $row->status === 'approved' ? 'You\'re already approved.' : 'Your application is with our team. We\'ll email you if anything needs changing.'];
        }
        $data = self::data($row);
        $errs = [];
        foreach (self::FIELDS as $k => $label) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            [$v, $e] = self::clean($k, $in[$k]);
            if ($e) {
                $errs[$k] = $e;
            } else {
                $data[$k] = $v;
            }
        }
        if (array_key_exists('nin', $in) && trim((string) $in['nin']) !== '') {
            $n = preg_replace('/\D/', '', (string) $in['nin']);
            strlen($n) === 11 ? $data['nin_enc'] = Crypt::encryptString($n) : $errs['nin'] = 'Your NIN is 11 digits.';
        }
        if (array_key_exists('account_number', $in) && trim((string) $in['account_number']) !== '') {
            $n = preg_replace('/\D/', '', (string) $in['account_number']);
            strlen($n) === 10 ? $data['account_number_enc'] = Crypt::encryptString($n) : $errs['account_number'] = 'Your account number is 10 digits.';
        }
        if (!empty($in['agree'])) {
            $data['agreed_at'] = now()->toIso8601String();
        }
        DB::table(self::TABLE)->where('id', $row->id)->update(['data' => json_encode($data), 'updated_at' => now()]);

        if ($errs) {
            return ['error' => reset($errs), 'fields' => $errs];
        }
        if (!$submit) {
            return ['ok' => true, 'saved' => true];
        }

        $docs    = self::docs($user);
        $missing = self::missing($data, $docs);
        if ($missing) {
            return ['error' => 'Please complete: ' . implode(', ', array_slice($missing, 0, 4)) . (count($missing) > 4 ? ' and ' . (count($missing) - 4) . ' more' : '') . '.', 'missing' => $missing];
        }
        if (empty($data['agreed_at'])) {
            return ['error' => 'Please tick the box to agree.'];
        }

        // create / update the Fleet-Ops driver (pending until approved)
        $s = TravoDriver::settings();
        $d = TravoDriver::driverFor($user);
        if (!$d) {
            $d = Driver::create(['company_uuid' => $s['operator_company'], 'user_uuid' => $user->uuid, 'status' => 'pending', 'online' => 0, 'country' => 'NG']);
        } elseif ($d->status !== 'active') {
            $d->status = 'pending';
        }
        $d->drivers_license_number = $data['licence_number'];
        $d->city                   = Str::limit($data['area'], 60, '');
        $d->save();
        TravoDriver::saveMetaPublic($d, ['vehicle' => $data['vehicle'], 'plate' => $data['plate'], 'area' => $data['area'], 'phone' => $data['phone'],
            'agreed_at' => $data['agreed_at'], 'applied_at' => now()->toIso8601String()]);
        $wasChanges = $row->status === 'changes';
        DB::table(self::TABLE)->where('id', $row->id)->update(['status' => 'submitted', 'driver_uuid' => $d->uuid, 'submitted_at' => now(), 'updated_at' => now()]);
        if (empty($user->phone)) {
            rescue(fn () => DB::table('users')->where('uuid', $user->uuid)->update(['phone' => $data['phone']]), null, false);
        }
        TravoDriver::mail($s['staff_email'], '[Travo] ' . ($wasChanges ? 'Updated' : 'New') . ' driver application: ' . $data['full_name'],
            $wasChanges ? 'Driver application updated' : 'New driver application',
            [$data['full_name'] . ' (' . ($user->email ?? '') . ', ' . $data['phone'] . ') ' . ($wasChanges ? 'has updated their application.' : 'wants to drive with Travo.'),
             'Vehicle: ' . (TravoDriver::VEHICLES[$data['vehicle']] ?? $data['vehicle']) . ' · ' . $data['make'] . ' ' . $data['model'] . ' · ' . $data['plate'] . ' · ' . $data['area'],
             'Review their details and documents in Drivers (admin).'],
            'https://travo.ng/#drivers-admin', 'Review the application');
        self::alertAdmins($row->uuid, $d->public_id, $data, $wasChanges);

        return ['ok' => true, 'submitted' => true];
    }

    /** Bell (Alerts box) entry for every Travo admin login: "a driver has completed their application". */
    private static function alertAdmins(string $appUuid, string $driverId, array $data, bool $updated): void
    {
        $support = (array) (TravoChat::settings()['support_users'] ?? []);
        $admins  = DB::table('users')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('type', 'admin')->orWhereIn('public_id', $support))->pluck('uuid');
        $veh = trim((TravoDriver::VEHICLES[$data['vehicle']] ?? '') . ' · ' . ($data['plate'] ?? ''), ' ·');
        foreach ($admins as $uuid) {
            rescue(fn () => TravoAlerts::send($uuid, 'drvapp:' . $appUuid . ':' . now()->format('YmdHis'),
                '🚚 ' . ($updated ? 'Driver application updated: ' : 'New driver application: ') . $data['full_name'],
                $data['full_name'] . ' has ' . ($updated ? 'updated and resubmitted' : 'completed') . ' their driver application (' . $veh . ', ' . $data['area'] . '). All details and documents are in. Tap to review.',
                ['kind' => 'driver', 'url' => '#drivers-admin', 'driver' => $driverId]), null, false);
        }
    }

    public static function upload(object $user, string $type, UploadedFile $file): array
    {
        if (!isset(self::DOCS[$type])) {
            return ['error' => 'Unknown document.'];
        }
        $row = self::row($user);
        if (in_array($row->status, ['submitted', 'approved'], true)) {
            return ['error' => 'Your application is with our team, so documents can\'t be changed right now.'];
        }
        $ext  = Str::lower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');
        $mime = (string) ($file->getMimeType() ?: '');
        $ok   = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'], true) || (self::DOCS[$type]['pdf'] && $ext === 'pdf' && $mime === 'application/pdf');
        if (!$ok || $file->getSize() > 10 * 1024 * 1024) {
            return ['error' => 'Upload a photo (JPG, PNG, HEIC)' . (self::DOCS[$type]['pdf'] ? ' or a PDF' : '') . ' up to 10 MB.'];
        }
        $old  = DB::table('files')->where('folder', 'travo-driver-docs')->where('subject_uuid', $user->uuid)->where('type', $type)->whereNull('deleted_at')->get(['uuid', 'path']);
        $fuid = (string) Str::uuid();
        $path = 'travo-driver-docs/' . $user->uuid . '/' . $type . '-' . Str::random(10) . '.' . $ext;
        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
        DB::table('files')->insert([
            'uuid' => $fuid, 'public_id' => 'file_' . Str::random(7), 'company_uuid' => TravoDriver::settings()['operator_company'], 'uploader_uuid' => $user->uuid,
            'subject_uuid' => $user->uuid, 'subject_type' => 'travo:driver-application', 'disk' => 'local', 'path' => $path, 'folder' => 'travo-driver-docs',
            'original_filename' => $type . '.' . $ext, 'type' => $type, 'content_type' => $ext === 'pdf' ? 'application/pdf' : ($mime ?: 'image/jpeg'),
            'file_size' => $file->getSize(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($old as $o) {
            DB::table('files')->where('uuid', $o->uuid)->update(['deleted_at' => now()]);
            rescue(fn () => Storage::disk('local')->delete($o->path), null, false);
        }

        return ['ok' => true, 'doc' => ['id' => $fuid, 'name' => $type . '.' . $ext, 'pdf' => $ext === 'pdf']];
    }

    /** The document file, for its owner or Travo staff. */
    public static function docFile(object $user, string $fileUuid): ?object
    {
        $f = DB::table('files')->where('uuid', $fileUuid)->where('folder', 'travo-driver-docs')->whereNull('deleted_at')->first();
        if (!$f) {
            return null;
        }

        return ($f->subject_uuid === $user->uuid || TravoChat::isStaff($user)) ? $f : null;
    }

    /** Staff view: everything, secrets decrypted. */
    public static function adminView(string $driverId, object $staff): array
    {
        $d = TravoDriver::find($driverId);
        if (!$d) {
            return ['error' => 'Driver not found'];
        }
        $u   = DB::table('users')->where('uuid', $d->user_uuid)->first(['uuid', 'name', 'email', 'phone', 'created_at']);
        $row = self::forDriver($d);
        if (!$row || !$u) {
            return ['driver' => $d->public_id, 'application' => null, 'user' => $u ? ['name' => $u->name, 'email' => $u->email, 'phone' => $u->phone] : null];
        }
        $data = self::data($row);
        $out  = array_intersect_key($data, self::FIELDS);
        foreach (self::SECRET as $k => $l) {
            $out[$k] = self::dec($data[$k . '_enc'] ?? null);
        }
        Log::info('travo-driver: application viewed', ['driver' => $d->public_id, 'by' => $staff->email ?? $staff->uuid]);

        return [
            'driver'      => $d->public_id,
            'status'      => $d->status,
            'user'        => ['name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'since' => Carbon::parse($u->created_at)->toIso8601String()],
            'application' => ['status' => $row->status, 'note' => $row->note, 'data' => $out, 'docs' => self::docs($u), 'missing' => self::missing($data, self::docs($u)),
                'submittedAt' => $row->submitted_at ? Carbon::parse($row->submitted_at)->toIso8601String() : null,
                'reviewedAt' => $row->reviewed_at ? Carbon::parse($row->reviewed_at)->toIso8601String() : null],
            'docTypes'    => self::DOCS,
            'vehicles'    => TravoDriver::VEHICLES,
        ];
    }

    /** approve | changes | reject */
    public static function review(string $driverId, string $decision, ?string $note, object $staff): array
    {
        $d = TravoDriver::find($driverId);
        if (!$d) {
            return ['error' => 'Driver not found'];
        }
        $row  = self::forDriver($d);
        $u    = DB::table('users')->where('uuid', $d->user_uuid)->first(['uuid', 'name', 'email']);
        $note = $note ? Str::limit(trim($note), 500, '') : null;
        if ($decision === 'changes' && !$note) {
            return ['error' => 'Tell the driver what to change.'];
        }
        $first = trim(explode(' ', trim((string) optional($u)->name))[0] ?? '');

        if ($decision === 'approve') {
            $d->status = 'active';
            $vehicle   = $row ? self::prefill($d, self::data($row)) : null;
            $d->save();
        } elseif ($decision === 'reject') {
            $d->status = 'rejected';
            $d->online = 0;
            $d->save();
        } else {
            $d->status = 'pending';
            $d->save();
        }
        if ($row) {
            DB::table(self::TABLE)->where('id', $row->id)->update(['status' => ['approve' => 'approved', 'reject' => 'rejected', 'changes' => 'changes'][$decision],
                'note' => $note, 'reviewed_at' => now(), 'reviewed_by_uuid' => $staff->uuid, 'updated_at' => now()]);
        }
        TravoDriver::saveMetaPublic($d, ['reviewed_at' => now()->toIso8601String(), 'review_note' => $note]);

        if ($u && $u->email) {
            if ($decision === 'approve') {
                // welcome email + 5 alerts: welcome, how you earn, examples for their vehicle, what we expect, refer a driver
                $veh = $row ? (self::data($row)['vehicle'] ?? null) : null;
                rescue(fn () => TravoDriverWelcome::send($d, $u, $veh, $note), null, true);
            } elseif ($decision === 'changes') {
                TravoDriver::mail($u->email, 'Please update your Travo driver application', 'One more thing' . ($first ? ', ' . $first : ''),
                    ['Thanks for applying to drive with Travo. Before we can approve you, please update your application:', $note,
                     'Open Drive with Travo, make the change and tap Submit again.'],
                    'https://travo.ng/#drive', 'Update my application');
            } else {
                TravoDriver::mail($u->email, 'Your Travo driver application', 'About your driver application',
                    ['Thank you for applying to drive with Travo. We can\'t approve your application at the moment.' . ($note ? ' ' . $note : ''),
                     'If your details change, you can update them and apply again from Drive with Travo.'],
                    'https://travo.ng/#drive', 'Open Drive with Travo');
            }
        }

        return ['ok' => true, 'vehicle' => $vehicle ?? null];
    }

    /** Fill in the Fleet-Ops driver + vehicle from the application. Returns the vehicle public_id. */
    private static function prefill(Driver $d, array $a): ?string
    {
        $d->drivers_license_number = $a['licence_number'] ?? $d->drivers_license_number;
        if (!empty($a['area'])) {
            $d->city = Str::limit($a['area'], 60, '');
        }
        $d->country = 'NG';
        if (empty($a['make']) && empty($a['plate'])) {
            return null;
        }
        $photo = DB::table('files')->where('folder', 'travo-driver-docs')->where('subject_uuid', $d->user_uuid)->where('type', 'vehicle')->whereNull('deleted_at')->value('uuid');
        $vals  = [
            'make' => $a['make'] ?? null, 'model' => $a['model'] ?? null, 'year' => $a['year'] ?? null, 'plate_number' => $a['plate'] ?? null,
            'status' => 'active', 'photo_uuid' => $photo, 'avatar_url' => TravoDriver::vehicleIcon($a['vehicle'] ?? null),
        ];
        $v = $d->vehicle_uuid ? Vehicle::where('uuid', $d->vehicle_uuid)->first() : null;
        if (!$v && !empty($a['plate'])) {
            $v = Vehicle::where('company_uuid', $d->company_uuid)->where('plate_number', $a['plate'])->whereNull('deleted_at')->first();
        }
        if ($v) {
            $v->fill(array_filter($vals, fn ($x) => $x !== null))->save();
        } else {
            $v = Vehicle::create(array_filter($vals, fn ($x) => $x !== null) + ['company_uuid' => $d->company_uuid, 'online' => 0]);
        }
        $meta = DB::table('vehicles')->where('uuid', $v->uuid)->value('meta');
        $meta = is_string($meta) ? (json_decode($meta, true) ?: []) : (array) $meta;
        $meta['travo'] = ['type' => $a['vehicle'] ?? null, 'colour' => $a['colour'] ?? null, 'driver' => $d->public_id];
        DB::table('vehicles')->where('uuid', $v->uuid)->update(['meta' => json_encode($meta)]);
        $d->vehicle_uuid = $v->uuid;

        return $v->public_id;
    }

    /** Let a rejected driver start again (keeps their details and documents). */
    public static function reopen(object $user): array
    {
        $row = self::forUser($user);
        if ($row && $row->status === 'rejected') {
            DB::table(self::TABLE)->where('id', $row->id)->update(['status' => 'draft', 'updated_at' => now()]);
        }

        return ['ok' => true];
    }

    public static function statusFor(array $driverUuids): array
    {
        self::ensure();

        return DB::table(self::TABLE)->whereIn('driver_uuid', $driverUuids)->pluck('status', 'driver_uuid')->all();
    }
}
