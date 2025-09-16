<?php

// app/Http/Requests/PurchaseStoreRequest.php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest {
  public function authorize(): bool { return true; }
  public function rules(): array {
    return [
      'supplier_id' => 'required|exists:suppliers,id',
      'order_date'  => 'required|date',
      'expected_date'=> 'nullable|date',
      'notes'       => 'nullable|string',
      'other_cost'  => 'nullable|numeric|min:0',
      'items'               => 'required|array|min:1',
      'items.*.product_id'  => 'required|exists:products,id',
      'items.*.qty_order'   => 'required|integer|min:1',
      'items.*.unit_price'  => 'required|numeric|min:0',
      'items.*.discount'    => 'nullable|numeric|min:0',
      'items.*.tax'         => 'nullable|numeric|min:0',
    ];
  }
}

