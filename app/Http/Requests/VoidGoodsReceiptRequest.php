<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'nullable|string|min:3|max:500',
        ];
    }
}
