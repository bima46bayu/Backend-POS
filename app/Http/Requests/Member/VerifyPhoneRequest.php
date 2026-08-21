<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class VerifyPhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['challenge_id' => ['required', 'uuid'], 'phone' => ['required', 'string', 'max:30'], 'otp' => ['required', 'digits:6'], 'name' => ['required', 'string', 'max:120'], 'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()]];
    }
}
