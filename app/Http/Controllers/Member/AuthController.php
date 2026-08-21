<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberProfileResource;
use App\Models\Member;
use App\Models\MemberAccount;
use App\Services\MemberOtpService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Single OTP endpoint for both registration and password reset.
     *
     * The two cases have opposite preconditions — registering requires that no
     * account exists yet, resetting requires that one does — so `purpose`
     * selects which check to apply. Member-Mobile always sends it.
     */
    public function requestRegistrationOtp(Request $request, MemberOtpService $otp)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'purpose' => ['nullable', 'in:register,reset_password'],
        ]);
        $phone = PhoneNormalizer::normalize($data['phone']);
        $purpose = $data['purpose'] ?? 'register';

        if ($purpose === 'reset_password') {
            $account = MemberAccount::where('phone', $phone)->where('is_active', true)->first();
            if (! $account) {
                throw ValidationException::withMessages(['phone' => ['Akun member aktif tidak ditemukan.']]);
            }
        } else {
            $member = Member::where('phone', $phone)->where('is_active', true)->first();
            if (! $member) {
                throw ValidationException::withMessages(['phone' => ['Nomor ini belum terdaftar sebagai member aktif. Hubungi kasir untuk mendaftar.']]);
            }
            if (MemberAccount::where('member_id', $member->id)->orWhere('phone', $phone)->exists()) {
                throw ValidationException::withMessages(['phone' => ['Akun untuk nomor ini sudah aktif. Silakan masuk.']]);
            }
        }

        $challenge = $otp->issue($phone, $purpose);

        return response()->json(['challenge_id' => $challenge->id, 'expires_at' => $challenge->expires_at->toIso8601String(), 'delivery' => 'local_log'], 202);
    }

    public function register(Request $request, MemberOtpService $otp)
    {
        $data = $request->validate([
            'challenge_id' => ['nullable', 'uuid'],
            'phone' => ['required', 'string', 'max:30'],
            'otp' => ['required', 'digits:6'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $phone = PhoneNormalizer::normalize($data['phone']);
        $challengeId = $data['challenge_id'] ?? \App\Models\MemberOtpChallenge::where('phone', $phone)->where('purpose', 'register')->whereNull('consumed_at')->latest()->value('id');
        if (! $challengeId) {
            throw ValidationException::withMessages(['otp' => ['OTP challenge tidak ditemukan.']]);
        }
        $otp->consume($challengeId, $phone, 'register', $data['otp']);
        $account = DB::transaction(function () use ($data, $phone) {
            $member = Member::where('phone', $phone)->where('is_active', true)->lockForUpdate()->first();
            if (! $member) {
                throw ValidationException::withMessages(['phone' => ['Nomor ini tidak terhubung ke member aktif.']]);
            }
            if (MemberAccount::where('member_id', $member->id)->orWhere('phone', $phone)->exists()) {
                throw ValidationException::withMessages(['phone' => ['Akun member sudah terdaftar.']]);
            }
            $member->update(['name' => $data['name'], 'email' => $data['email'] ?? $member->email]);

            return MemberAccount::create(['member_id' => $member->id, 'phone' => $phone, 'password' => $data['password'], 'phone_verified_at' => now()]);
        });

        return $this->tokenResponse($account, 201);
    }

    public function resetPassword(Request $request, MemberOtpService $otp)
    {
        $data = $request->validate(['challenge_id' => ['nullable', 'uuid'], 'phone' => ['required', 'string'], 'otp' => ['required', 'digits:6'], 'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()]]);
        $phone = PhoneNormalizer::normalize($data['phone']);
        $otp->consume($data['challenge_id'], $phone, 'reset_password', $data['otp']);
        $account = MemberAccount::where('phone', $phone)->where('is_active', true)->firstOrFail();
        $account->update(['password' => $data['password']]);
        $account->tokens()->delete();

        return $this->tokenResponse($account);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['phone' => ['required', 'string'], 'password' => ['required', 'string']]);
        $account = MemberAccount::where('phone', PhoneNormalizer::normalize($data['phone']))->first();
        if (! $account || ! Hash::check($data['password'], $account->password) || ! $account->phone_verified_at) {
            throw ValidationException::withMessages(['phone' => ['Kredensial member tidak valid.']]);
        }
        if (! $account->is_active || ! $account->member?->is_active) {
            abort(403, 'Member account is inactive.');
        }
        $account->tokens()->delete();
        $account->update(['last_login_at' => now()]);

        return $this->tokenResponse($account);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    }

    private function tokenResponse(MemberAccount $account, int $status = 200)
    {
        return response()->json(['token' => $account->createToken('member-mobile', ['member'])->plainTextToken, 'member' => new MemberProfileResource($account->load('member'))], $status);
    }
}
