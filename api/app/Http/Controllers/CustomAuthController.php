<?php

namespace App\Http\Controllers;

use Fleetbase\Http\Controllers\Internal\v1\AuthController as BaseAuthController;
use Illuminate\Http\Request;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Notifications\UserForgotPassword;
use Illuminate\Support\Carbon;
use Fleetbase\Support\Utils;

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
}
