<?php
// app/Http/Controllers/PurchaseReceiveController.php
namespace App\Http\Controllers;

use App\Http\Requests\PurchaseReceiveRequest;
use App\Models\{Purchase, PurchaseItem, GoodsReceipt, GoodsReceiptItem, StockLog, Product};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;

class PurchaseReceiveController extends Controller
{
  // preload remaining untuk UI
  public function forReceipt(Purchase $purchase) {
    if (!in_array($purchase->status,['approved','partially_received'])) {
      return response()->json(['message'=>'PO must be approved'],422);
    }
    $items = $purchase->items()->with('product:id,sku,name')->get()->map(function($pi){
      $remaining = $pi->qty_order - $pi->qty_received;
      return [
        'purchase_item_id'     => $pi->id,
        'product_id'           => $pi->product_id,
        'product_label'        => $pi->product? "{$pi->product->sku} - {$pi->product->name}" : null,
        'qty_order'            => $pi->qty_order,
        'qty_received_so_far'  => $pi->qty_received,
        'qty_remaining'        => $remaining,
        'unit_price'           => $pi->unit_price,
      ];
    });
    return response()->json([
      'purchase_id'     => $purchase->id,
      'purchase_number' => $purchase->purchase_number,
      'items'           => $items,
    ]);
  }

  // create + post GR (stok naik + stock_logs)
  public function receive(PurchaseReceiveRequest $req, Purchase $purchase) {
    if (!in_array($purchase->status,['approved','partially_received'])) {
      throw ValidationException::withMessages(['status'=>'PO must be approved to receive.']);
    }
    $data = $req->validated();
    $lines = collect($data['items'])->filter(fn($x)=>(int)$x['qty_received']>0)->values();
    if ($lines->isEmpty()) {
      throw ValidationException::withMessages(['items'=>'No quantities to receive.']);
    }
    $userId = $req->user()->id;

    $gr = DB::transaction(function() use ($purchase,$lines,$data,$userId) {
      // lock PO items
      $poItems = PurchaseItem::where('purchase_id',$purchase->id)
                  ->whereIn('id',$lines->pluck('purchase_item_id'))
                  ->lockForUpdate()->get()->keyBy('id');

      // validate remaining
      foreach ($lines as $row) {
        $pi = $poItems[$row['purchase_item_id']];
        $remaining = $pi->qty_order - $pi->qty_received;
        if ((int)$row['qty_received'] > $remaining) {
          throw ValidationException::withMessages([
            'qty_received'=>"Over receive on item {$pi->id}. Remaining {$remaining}."
          ]);
        }
      }

      // create GR (draft)
      $gr = GoodsReceipt::create([
        'gr_number'    => GoodsReceipt::nextNumber(),
        'purchase_id'  => $purchase->id,
        'received_by'  => $userId,
        'received_date'=> $data['received_date'] ?? now()->toDateString(),
        'status'       => 'draft',
        'notes'        => $data['notes'] ?? null,
      ]);

      // per line: simpan GR item, update qty_received, update stok, tulis stock_logs
      foreach ($lines as $row) {
        $pi  = $poItems[$row['purchase_item_id']];
        $qty = (int)$row['qty_received'];

        GoodsReceiptItem::create([
          'goods_receipt_id' => $gr->id,
          'purchase_item_id' => $pi->id,
          'qty_received'     => $qty,
          'condition_notes'  => $row['condition_notes'] ?? null,
        ]);

        // update kumulatif diterima
        $pi->qty_received += $qty;
        $pi->save();

        // increment counter stok produk (lock row untuk hindari race)
        $product = Product::whereKey($pi->product_id)->lockForUpdate()->first();
        $product->increment('stock', $qty);

        // TULIS STOCK LOGS (tidak merubah skema)
        StockLog::create([
          'product_id'  => $pi->product_id,
          'user_id'     => $userId,
          'change_type' => 'in',
          'quantity'    => $qty,
          'note'        => "GR {$gr->gr_number} / PO {$purchase->purchase_number}",
        ]);
      }

      // update status PO
      $stillOpen = PurchaseItem::where('purchase_id',$purchase->id)
                    ->whereColumn('qty_received','<','qty_order')->exists();
      $purchase->update(['status'=>$stillOpen ? 'partially_received' : 'closed']);

      // post GR
      $gr->update(['status'=>'posted']);

      return $gr;
    });

    return response()->json([
      'message'=>'GR posted',
      'gr'=>['id'=>$gr->id,'gr_number'=>$gr->gr_number],
      'purchase'=>['id'=>$purchase->id,'status'=>$purchase->status],
    ]);
  }
}
