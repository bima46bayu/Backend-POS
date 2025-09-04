<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'customer_name'    => 'nullable|string|max:100',
            'items'            => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty'        => 'required|integer|min:1',
            // optional override price? biasanya ambil dari DB:
            'discount'         => 'nullable|numeric|min:0',
            'tax'              => 'nullable|numeric|min:0',
            'payments'         => 'required|array|min:1',
            'payments.*.method'=> 'required|in:cash,card,ewallet,transfer',
            'payments.*.amount'=> 'required|numeric|min:0.01',
            'payments.*.reference' => 'nullable|string|max:100',
        ];
    }
}
