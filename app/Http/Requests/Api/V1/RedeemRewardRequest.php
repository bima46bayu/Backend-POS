<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class RedeemRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Pickup store. Defaults to the member's home store when omitted.
            'store_location_id' => ['nullable', 'integer', 'exists:store_locations,id'],
        ];
    }

    /** The client-generated key that makes a retried redeem safe. */
    public function idempotencyKey(): string
    {
        $key = trim((string) $this->header('Idempotency-Key', ''));

        if ($key === '' || strlen($key) > 100) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['An Idempotency-Key header (1–100 chars) is required.'],
            ]);
        }

        return $key;
    }
}
