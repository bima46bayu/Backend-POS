<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use Illuminate\Http\Request;
use App\Models\GoodsReceiptItem;
use App\Http\Requests\PurchaseReceiveRequest;

class GoodsReceiptController extends Controller
{
  public function index(Request $r) {
    $q = GoodsReceipt::with(['purchase.supplier:id,name'])
      ->when($r->status, fn($qq,$v)=>$qq->where('status',$v))
      ->when($r->from, fn($qq,$v)=>$qq->whereDate('received_date','>=',$v))
      ->when($r->to, fn($qq,$v)=>$qq->whereDate('received_date','<=',$v))
      ->orderByDesc('id');
    return response()->json($q->paginate(min(100,(int)($r->per_page ?? 15))));
  }

  public function show(GoodsReceipt $goodsReceipt) {
    return $goodsReceipt->load([
      'purchase:id,purchase_number,supplier_id',
      'purchase.supplier:id,name',
      'items.purchaseItem:id,purchase_id,product_id,qty_order,qty_received,unit_price',
      'items.purchaseItem.product:id,sku,name'
    ]);
  }
}

