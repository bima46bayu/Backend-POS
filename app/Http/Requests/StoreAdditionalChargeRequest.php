<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdditionalChargeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type'      => 'required|in:PB1,SERVICE',
            'calc_type' => 'required|in:PERCENT,FIXED',
            'value'     => 'required|numeric|min:0',
            'is_active' => 'boolean',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
