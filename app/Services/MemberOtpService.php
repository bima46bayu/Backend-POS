<?php

namespace App\Services;

use App\Contracts\OtpProvider;
use App\Models\MemberOtpChallenge;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MemberOtpService
{
    public function __construct(private OtpProvider $provider) {}

    public function issue(string $phone, string $purpose): MemberOtpChallenge
    {
        $phone = PhoneNormalizer::normalize($phone);
        MemberOtpChallenge::where('phone', $phone)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        $code = (string) random_int(100000, 999999);
        $challenge = MemberOtpChallenge::create(['id' => (string) Str::uuid(), 'phone' => $phone, 'purpose' => $purpose, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10)]);
        $this->provider->deliver($phone, $code, $purpose);

        return $challenge;
    }

    public function consume(string $id, string $phone, string $purpose, string $code): MemberOtpChallenge
    {
        $challenge = MemberOtpChallenge::whereKey($id)->where('phone', PhoneNormalizer::normalize($phone))->where('purpose', $purpose)->first();
        if (! $challenge || $challenge->consumed_at || $challenge->expires_at->isPast() || $challenge->attempts >= $challenge->max_attempts) {
            throw ValidationException::withMessages(['otp' => ['OTP tidak valid atau sudah kedaluwarsa.']]);
        } $challenge->increment('attempts');
        if (! Hash::check($code, $challenge->code_hash)) {
            throw ValidationException::withMessages(['otp' => ['OTP tidak valid atau sudah kedaluwarsa.']]);
        } $challenge->update(['consumed_at' => now()]);

        return $challenge;
    }
}
