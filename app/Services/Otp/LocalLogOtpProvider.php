<?php

namespace App\Services\Otp;

use App\Contracts\OtpProvider;
use Illuminate\Support\Facades\Log;

final class LocalLogOtpProvider implements OtpProvider
{
    public function deliver(string $phone, string $code, string $purpose): void
    {
        Log::info('Local member OTP delivery', compact('phone', 'code', 'purpose'));
    }
}
