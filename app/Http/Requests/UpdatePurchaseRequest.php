<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_date'    => 'nullable|date',
            'expected_date' => 'nullable|date',
            'notes'         => 'nullable|string',
            'other_cost'    => 'nullable|numeric|min:0',
            'items'         => 'nullable|array|min:1',
            'items.*.id'    => 'required_with:items|integer|exists:purchase_items,id',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.discount'   => 'nullable|numeric|min:0',
            'items.*.tax'        => 'nullable|numeric|min:0',
        ];
    }
}
