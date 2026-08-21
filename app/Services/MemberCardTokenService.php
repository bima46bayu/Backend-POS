<?php

namespace App\Services;

use App\Models\MemberAccount;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class MemberCardTokenService
{
    public function issue(MemberAccount $account): array
    {
        $expires = now()->addMinutes(5);
        $token = Crypt::encryptString(json_encode([
            'v' => 1,
            'jti' => (string) Str::uuid(),
            'member_account_id' => $account->id,
            'member_id' => $account->member_id,
            'exp' => $expires->timestamp,
        ], JSON_THROW_ON_ERROR));

        return ['value' => $token, 'token' => $token, 'expires_at' => $expires->toIso8601String()];
    }

    public function resolve(string $token): ?MemberAccount
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
            if (($payload['v'] ?? null) !== 1 || (int) ($payload['exp'] ?? 0) < time()) {
                return null;
            }

            return MemberAccount::with('member:id,code,name,phone,is_active')->find($payload['member_account_id'] ?? null);
        } catch (\Throwable) {
            return null;
        }
    }
}
