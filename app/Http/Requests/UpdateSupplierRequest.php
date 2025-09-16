<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'      => 'sometimes|required|string|max:150',
            'contact'   => 'sometimes|nullable|string|max:150',

            'type'      => 'sometimes|nullable|in:marketplace,retail,corporate,others',
            'address'   => 'sometimes|nullable|string|max:255',
            'phone'     => 'sometimes|nullable|string|max:50',
            'email'     => 'sometimes|nullable|email|max:150',
            'pic_name'  => 'sometimes|nullable|string|max:100',
            'pic_phone' => 'sometimes|nullable|string|max:50',
        ];
    }
}
