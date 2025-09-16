<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseRequest;
use App\Models\{Purchase, PurchaseItem};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\PurchaseReceiveRequest;

class PurchaseController extends Controller
{
  public function index(Request $r) {
    $q = Purchase::with(['supplier:id,name','items.product:id,sku,name'])
      ->when($r->status, fn($qq,$v)=>$qq->where('status',$v))
      ->when($r->supplier_id, fn($qq,$v)=>$qq->where('supplier_id',$v))
      ->when($r->from, fn($qq,$v)=>$qq->whereDate('order_date','>=',$v))
      ->when($r->to, fn($qq,$v)=>$qq->whereDate('order_date','<=',$v))
      ->orderByDesc('id');

    return response()->json($q->paginate(min(100,(int)($r->per_page ?? 15))));
  }

  public function show(Purchase $purchase) {
    return $purchase->load([
      'supplier:id,name',
      'items.product:id,sku,name'
    ]);
  }

  // Create PO (draft)
  public function store(StorePurchaseRequest $req) {
    $data = $req->validated(); $userId = $req->user()->id;

    $po = DB::transaction(function() use ($data,$userId) {
      $po = Purchase::create([
        'purchase_number'=> Purchase::nextNumber(),
        'supplier_id'    => $data['supplier_id'],
        'user_id'        => $userId,
        'order_date'     => $data['order_date'],
        'expected_date'  => $data['expected_date'] ?? null,
        'status'         => 'draft',
        'notes'          => $data['notes'] ?? null,
        'other_cost'     => (float)($data['other_cost'] ?? 0),
      ]);

      $subtotal=0; $taxTotal=0;
      foreach ($data['items'] as $it) {
        $qty=(int)$it['qty_order']; $price=(float)$it['unit_price'];
        $disc=(float)($it['discount'] ?? 0); $tax=(float)($it['tax'] ?? 0);
        $line = ($qty*$price) - $disc + $tax;

        PurchaseItem::create([
          'purchase_id' => $po->id,
          'product_id'  => $it['product_id'],
          'qty_order'   => $qty,
          'qty_received'=> 0,
          'unit_price'  => $price,
          'discount'    => $disc,
          'tax'         => $tax,
          'line_total'  => $line,
        ]);

        $subtotal += ($qty*$price) - $disc;
        $taxTotal += $tax;
      }

      $po->update([
        'subtotal'    => $subtotal,
        'tax_total'   => $taxTotal,
        'grand_total' => $subtotal + $taxTotal + $po->other_cost,
      ]);

      return $po->load('items.product:id,sku,name');
    });

    return response()->json($po, 201);
  }

  // Approve PO
  public function approve(Request $r, Purchase $purchase) {
    if ($purchase->status !== 'draft') {
      return response()->json(['message'=>'Only draft can be approved'],422);
    }
    if (!$purchase->items()->exists()) {
      return response()->json(['message'=>'No items to approve'],422);
    }
    $purchase->update([
      'status'=>'approved',
      'approved_by'=>$r->user()->id,
      'approved_at'=>now()
    ]);
    return response()->json(['message'=>'Purchase approved','status'=>'approved']);
  }

  // Cancel PO (optional)
  public function cancel(Purchase $purchase) {
    if (in_array($purchase->status,['closed','partially_received'])) {
      return response()->json(['message'=>'Cannot cancel received PO'],422);
    }
    $purchase->update(['status'=>'canceled']);
    return response()->json(['message'=>'Purchase canceled']);
  }
}


