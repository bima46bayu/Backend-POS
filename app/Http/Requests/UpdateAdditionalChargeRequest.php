<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdditionalChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ⚠️ PARTIAL UPDATE (toggle / edit)
            'calc_type' => 'sometimes|in:PERCENT,FIXED',
            'value'     => 'sometimes|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
