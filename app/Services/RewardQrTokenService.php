<?php

namespace App\Services;

use App\Models\RewardReservation;

class RewardQrTokenService
{
    public function issue(RewardReservation $r): array
    {
        $expires = now()->addMinutes(5);
        $payload = $r->public_id.'|'.$expires->timestamp;

        return ['token' => base64_encode($payload.'|'.hash_hmac('sha256', $payload, config('app.key'))), 'expires_at' => $expires->toIso8601String()];
    }

    public function resolve(string $token): ?RewardReservation
    {
        $raw = base64_decode($token, true);
        if (! $raw) {
            return null;
        } $parts = explode('|', $raw);
        if (count($parts) !== 3 || (int) $parts[1] < time()) {
            return null;
        } $payload = $parts[0].'|'.$parts[1];
        if (! hash_equals(hash_hmac('sha256', $payload, config('app.key')), $parts[2])) {
            return null;
        }

return RewardReservation::where('public_id', $parts[0])->first();
    }
}
