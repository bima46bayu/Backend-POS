<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'      => 'required|string|max:150',
            'contact'   => 'nullable|string|max:150',
            
            // opsional (aktifkan hanya jika kolomnya ada di DB)
            'type'      => 'nullable|in:marketplace,retail,corporate,others',
            'address'   => 'nullable|string|max:255',
            'phone'     => 'nullable|string|max:50',
            'email'     => 'nullable|email|max:150',
            'pic_name'  => 'nullable|string|max:100',
            'pic_phone' => 'nullable|string|max:50',
        ];
    }
}
