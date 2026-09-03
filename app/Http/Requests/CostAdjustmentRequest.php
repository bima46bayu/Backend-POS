<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CostAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason'        => 'required|string|min:3|max:500',
            'new_unit_cost' => 'required_without:lines|nullable|numeric|min:0',
            'layer_id'      => 'nullable|integer',
            'qty_affected'  => 'nullable|numeric|min:0',
            'lines'         => 'required_without:new_unit_cost|array|min:1',
            'lines.*.layer_id' => 'required_without:lines.*.goods_receipt_item_id|integer',
            'lines.*.goods_receipt_item_id' => 'nullable|integer',
            'lines.*.new_unit_cost' => 'required|numeric|min:0',
        ];
    }
}
