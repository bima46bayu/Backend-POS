<?php

namespace App\Contracts;

interface OtpProvider
{
    public function deliver(string $phone, string $code, string $purpose): void;
}
