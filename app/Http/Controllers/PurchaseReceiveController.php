<?php
// app/Http/Controllers/PurchaseReceiveController.php
namespace App\Http\Controllers;

use App\Http\Requests\PurchaseReceiveRequest;
use App\Models\{Purchase, PurchaseItem, GoodsReceipt, GoodsReceiptItem, StockLog, Product};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Schema;

class PurchaseReceiveController extends Controller
{
  // preload remaining untuk UI
  public function forReceipt(Purchase $purchase) {
    if (!in_array($purchase->status, ['approved','partially_received'])) {
      return response()->json(['message'=>'PO must be approved'],422);
    }

    $items = $purchase->items()->with('product:id,sku,name')->get()->map(function($pi){
      $remaining = $pi->qty_order - $pi->qty_received;
      return [
        'purchase_item_id'     => $pi->id,
        'product_id'           => $pi->product_id,
        'product_label'        => $pi->product ? "{$pi->product->sku} - {$pi->product->name}" : null,
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

  // GR: stok naik + stock_logs + buat layer FIFO + catat ledger IN (tanpa store)
  public function receive(PurchaseReceiveRequest $req, Purchase $purchase) {
    if (!in_array($purchase->status, ['approved','partially_received'])) {
      throw ValidationException::withMessages(['status'=>'PO must be approved to receive.']);
    }

    $data  = $req->validated();
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
        $pi = $poItems[$row['purchase_item_id']] ?? null;
        if (!$pi) {
          throw ValidationException::withMessages([
            'items' => "Purchase item {$row['purchase_item_id']} tidak ditemukan."
          ]);
        }
        $remaining = $pi->qty_order - $pi->qty_received;
        if ((int)$row['qty_received'] > $remaining) {
          throw ValidationException::withMessages([
            'qty_received'=>"Over receive on item {$pi->id}. Remaining {$remaining}."
          ]);
        }
      }

      // create GR (draft)
      $gr = GoodsReceipt::create([
        'gr_number'     => GoodsReceipt::nextNumber(),
        'purchase_id'   => $purchase->id,
        'received_by'   => $userId,
        'received_date' => $data['received_date'] ?? now()->toDateString(),
        'status'        => 'draft',
        'notes'         => $data['notes'] ?? null,
      ]);

      // per line: GR item, update qty_received, stok, log, layer, ledger
      foreach ($lines as $row) {
        $pi  = $poItems[$row['purchase_item_id']];
        $qty = (int)$row['qty_received'];

        $grItem = GoodsReceiptItem::create([
          'goods_receipt_id' => $gr->id,
          'purchase_item_id' => $pi->id,
          'qty_received'     => $qty,
          'condition_notes'  => $row['condition_notes'] ?? null,
        ]);

        // kumulatif diterima
        $pi->qty_received += $qty;
        $pi->save();

        // legacy: naikkan stok produk
        $product = Product::whereKey($pi->product_id)->lockForUpdate()->first();
        $product->increment('stock', $qty);

        // legacy log
        StockLog::create([
          'product_id'  => $pi->product_id,
          'user_id'     => $userId,
          'change_type' => 'in',
          'quantity'    => $qty,
          'note'        => "GR {$gr->gr_number} / PO {$purchase->purchase_number}",
        ]);

        // ===== BUAT LAYER FIFO (tanpa store) =====
        app(InventoryService::class)->addInboundLayer([
          'product_id'        => $pi->product_id,
          'qty'               => (float)$qty,
          'unit_buy'          => (float)$pi->unit_price,
          'unit_tax'          => 0,
          'unit_other_cost'   => 0,
          // 'store_location_id' => null, // sengaja tidak dikirim
          'source_type'       => 'gr',
          'source_id'         => $grItem->id,
        ]);

        // ===== LEDGER IN (direction=+1, ref_type='GR', ref_id=$gr->id) =====
        if (Schema::hasTable('stock_ledger')) {
          // cari layer berdasar jejak (tanpa filter store)
          $layerId = DB::table('inventory_layers')
            ->where('product_id', $pi->product_id)
            ->where('source_type', 'gr')
            ->where('source_id', $grItem->id)
            ->orderByDesc('id')
            ->value('id');

          if ($layerId) {
            // fallback nama kolom qty & cost
            $qtyCol  = Schema::hasColumn('inventory_layers','qty') ? 'qty'
                     : (Schema::hasColumn('inventory_layers','qty_initial') ? 'qty_initial' : null);

            $costCol = Schema::hasColumn('inventory_layers','unit_landed_cost') ? 'unit_landed_cost'
                     : (Schema::hasColumn('inventory_layers','unit_cost')       ? 'unit_cost'
                     : (Schema::hasColumn('inventory_layers','unit_price')      ? 'unit_price' : null));

            $select = 'id, product_id, store_location_id';
            $select .= $qtyCol  ? ", {$qtyCol} as q" : ", 0 as q";
            $select .= $costCol ? ", {$costCol} as landed" : ", 0 as landed";

            $layer = DB::table('inventory_layers')
              ->selectRaw($select)
              ->where('id', $layerId)
              ->first();

            if ($layer) {
              $q = (float)$layer->q ?: (float)$qty; // fallback ke qty GR
              $c = (float)$layer->landed;

              DB::table('stock_ledger')->insert([
                'product_id'        => (int)$layer->product_id,
                'store_location_id' => $layer->store_location_id ? (int)$layer->store_location_id : null,
                'layer_id'          => (int)$layerId,
                'user_id'           => $userId,
                'ref_type'          => 'GR',
                'ref_id'            => $gr->id,
                'direction'         => +1,      // IN
                'qty'               => $q,
                'unit_cost'         => $c,
                'unit_price'        => null,
                'subtotal_cost'     => $q * $c,
                'note'              => "GR {$gr->gr_number}",
                'created_at'        => now(),
                'updated_at'        => now(),
              ]);
            }
          }
        }
        // ===== END LEDGER IN =====
      }

      // update status PO & GR
      $stillOpen = PurchaseItem::where('purchase_id',$purchase->id)
                    ->whereColumn('qty_received','<','qty_order')->exists();
      $purchase->update(['status'=>$stillOpen ? 'partially_received' : 'closed']);
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
