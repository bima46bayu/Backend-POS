<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Email (app sign-up) or phone number (OTP-registered accounts).
            'identifier' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:200'],
        ];
    }

    public function identifier(): string
    {
        return trim((string) $this->validated('identifier'));
    }

    public function isEmail(): bool
    {
        return str_contains($this->identifier(), '@');
    }
}
