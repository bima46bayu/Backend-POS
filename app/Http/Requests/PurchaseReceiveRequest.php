<?php

// app/Http/Requests/PurchaseReceiveRequest.php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class PurchaseReceiveRequest extends FormRequest {
  public function authorize(): bool { return true; }
  public function rules(): array {
    return [
      'received_date' => 'nullable|date',
      'notes'         => 'nullable|string',
      'items'                         => 'required|array|min:1',
      'items.*.purchase_item_id'      => 'required|exists:purchase_items,id',
      'items.*.qty_received'          => 'required|integer|min:0',
      'items.*.condition_notes'       => 'nullable|string',
    ];
  }
}
