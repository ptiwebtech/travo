<?php

namespace App\Http\Controllers;

use Fleetbase\Http\Controllers\Internal\v1\AuthController as BaseAuthController;
use Illuminate\Http\Request;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Notifications\UserForgotPassword;
use Illuminate\Support\Carbon;
use Fleetbase\Support\Utils;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Support\Facades\Storage;
use Fleetbase\Models\File;
use Illuminate\Support\Str;

class CustomAuthController extends BaseAuthController
{
    public function createPasswordReset(Request $request)
    {
        $user = User::where('email', $request->input('email'))->first();

        if (!$user) {
            if (!$user) {
                return response()->json(['error' => 'User not found.'], 200);
            }
        }

        $verificationCode = VerificationCode::create([
            'subject_uuid' => $user->uuid,
            'subject_type' => Utils::getModelClassName($user),
            'for'          => 'password_reset',
            'expires_at'   => Carbon::now()->addMinutes(15),
            'status'       => 'active',
        ]);

        $user->notify(new UserForgotPassword($verificationCode));

        return response()->json(['status' => 'ok']);
    }

    public function vendorSignup(Request $request)
    {
        // 1. Validation Rules
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'vendor.name'  => 'required|string|min:3|max:255',
            'vendor.email' => 'required|email|unique:vendors,email', // Email unique hona chahiye
            'vendor.phone' => 'required',
            'vendor.type'  => 'required',
        ], [
            'vendor.email.unique' => 'This email is already registered as a vendor.',
            'vendor.name.required' => 'Business name is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->first()
            ], 400);
        }

        $vendorData = $request->input('vendor');

        // ... (Aapka existing model creation code)
        $vendor = new Vendor();
        $vendor->uuid = (string) Str::uuid();
        $vendor->public_id = Vendor::generatePublicId('vendor');
        $vendor->company_uuid = '26e983b5-7c0f-462f-a902-19a1350d96b6'; 
        
        $vendor->name = $vendorData['name'];
        $vendor->email = $vendorData['email'];
        $vendor->phone = $vendorData['phone'] ?? null;
        $vendor->website_url = $vendorData['website_url'] ?? null;
        $vendor->type = $vendorData['type'] ?? 'general-vendor';
        $vendor->country = $vendorData['country'] ?? 'NG';
        $vendor->status = 'prospective';

        $vendor->meta = [
            'notes' => $vendorData['notes'] ?? '',
            'has_physical_location' => $vendorData['has_physical_location'] ?? false,
        ];
        
        if (!empty($vendorData['logo_data'])) {
            try {
                $base64String = $vendorData['logo_data'];
                if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $match)) {
                    $extension = strtolower($match[1]);
                    $data = base64_decode(substr($base64String, strpos($base64String, ',') + 1));
                    
                    $fileName = \Illuminate\Support\Str::random(10) . '.' . $extension;
                    $folder = 'uploads/vendor-signup';
                    $path = "{$folder}/{$fileName}";
    
                    // 1. Storage Check
                    if (!\Illuminate\Support\Facades\Storage::disk('public_images')->put($path, $data)) {
                        return response()->json(['error' => 'Failed to write file to disk public_images'], 500);
                    }
    
                    // 2. File Record Creation
                    $file = new \Fleetbase\Models\File();
                    $file->uuid = (string) \Illuminate\Support\Str::uuid();
                    $file->public_id = \Fleetbase\Models\File::generatePublicId('file');
                    $file->company_uuid = $vendor->company_uuid; // Yahan error ho sakta tha agar variable na ho
                    $file->subject_uuid = $vendor->uuid;
                    $file->subject_type = 'Fleetbase\FleetOps\Models\Vendor';
                    $file->disk = 'public_images'; 
                    $file->path = $path;
                    $file->original_filename = 'logo_' . \Illuminate\Support\Str::slug($vendor->name) . '.' . $extension;
                    $file->type = 'vendor_logo';
                    $file->content_type = 'image/' . $extension;
                    $file->file_size = strlen($data);
                    $file->slug = \Illuminate\Support\Str::slug($file->original_filename) . '-' . \Illuminate\Support\Str::random(4);
                    
                    if (!$file->save()) {
                        return response()->json(['error' => 'Failed to save File record in database'], 500);
                    }
    
                    $vendor->logo_uuid = $file->uuid;
                }
            } catch (\Exception $e) {
                // YAHAN DEBUG HOGA: Agar koi bhi error aayi toh response mein dikhega
                return response()->json([
                    'error' => 'Logo Processing Error: ' . $e->getMessage(),
                    'line' => $e->getLine()
                ], 500);
            }
        }

        try {
            $vendor->save();
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }
}
