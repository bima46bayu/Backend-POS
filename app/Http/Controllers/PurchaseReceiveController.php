<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseReceiveRequest;
use App\Models\{
    Purchase,
    PurchaseItem,
    GoodsReceipt,
    GoodsReceiptItem,
    Product
};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use App\Services\InventoryService;
class PurchaseReceiveController extends Controller
{
    public function forReceipt(Purchase $purchase)
    {
        if (!in_array($purchase->status, ['approved','partially_received'])) {
            return response()->json(['message'=>'PO must be approved'],422);
        }

        $items = $purchase->activeItems()->with([
            'product:id,sku,name,image_url,unit_id,pack_size,pack_label',
            'product.unit:id,name',
        ])->get()->map(function ($pi) {
            $remaining = $pi->qty_order - $pi->qty_received;
            $product = $pi->product;
            return [
                'purchase_item_id'     => $pi->id,
                'product_id'           => $pi->product_id,
                'sku'                  => $product?->sku,
                'product_name'         => $product?->name,
                'product_label'        => $product ? "{$product->sku} - {$product->name}" : null,
                'image_url'            => $product?->image_url,
                'unit_id'              => $product?->unit_id,
                'unit_name'            => $product?->unit?->name ?? $product?->unit_name,
                'qty_order'            => $pi->qty_order,
                'qty_received_so_far'  => $pi->qty_received,
                'qty_remaining'        => $remaining,
                'unit_price'           => $pi->unit_price,
                // Pack info is informational for the receiver: quantities on a PO
                // are always in stock units, so showing "isi 100/Pack" lets them
                // confirm that 5 packs really means the 500 they are typing.
                'pack_size'            => $product?->pack_size,
                'pack_label'           => $product?->pack_label,
            ];
        });

        return response()->json([
            'purchase_id'     => $purchase->id,
            'purchase_number' => $purchase->purchase_number,
            'items'           => $items,
        ]);
    }

    public function receive(PurchaseReceiveRequest $req, Purchase $purchase)
    {
        if (!in_array($purchase->status, ['approved','partially_received'])) {
            throw ValidationException::withMessages(['status'=>'PO must be approved to receive.']);
        }

        $data  = $req->validated();
        $lines = collect($data['items'])->filter(fn($x)=>(int)$x['qty_received']>0)->values();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['items'=>'No quantities to receive.']);
        }

        $userId = $req->user()->id;

        $poStoreId = $purchase->store_location_id ? (int) $purchase->store_location_id : null;

        $gr = DB::transaction(function() use ($purchase,$lines,$data,$userId,$poStoreId) {

            $poItems = PurchaseItem::where('purchase_id',$purchase->id)
                        ->whereIn('id',$lines->pluck('purchase_item_id'))
                        ->lockForUpdate()->get()->keyBy('id');

            // preload semua product untuk safety & efisiensi
            $productMap = Product::whereIn('id', $poItems->pluck('product_id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($lines as $row) {
                $pi = $poItems[$row['purchase_item_id']] ?? null;
                if (!$pi) {
                    throw ValidationException::withMessages([
                        'items' => "Purchase item {$row['purchase_item_id']} tidak ditemukan."
                    ]);
                }

                if ($pi->isCancelled()) {
                    throw ValidationException::withMessages([
                        'items' => "Purchase item {$pi->id} sudah dibatalkan.",
                    ]);
                }

                $product = $productMap[$pi->product_id] ?? null;
                if (!$product) {
                    throw ValidationException::withMessages([
                        'items' => "Produk untuk purchase item {$pi->id} tidak ditemukan."
                    ]);
                }

                // ❗ SAFETY: produk non-stock tidak boleh diterima sebagai stok
                if (! $product->isStockTracked()) {
                    throw ValidationException::withMessages([
                        'items' => "Produk '{$product->name}' adalah non-stock dan tidak boleh diterima di GR.",
                    ]);
                }

                $remaining = $pi->qty_order - $pi->qty_received;
                if ((int)$row['qty_received'] > $remaining) {
                    throw ValidationException::withMessages([
                        'qty_received'=>"Over receive on item {$pi->id}. Remaining {$remaining}."
                    ]);
                }
            }

            $gr = GoodsReceipt::create([
                'gr_number'     => GoodsReceipt::nextNumber(),
                'purchase_id'   => $purchase->id,
                'received_by'   => $userId,
                'received_date' => $data['received_date'] ?? now()->toDateString(),
                'status'        => 'draft',
                'notes'         => $data['notes'] ?? null,
            ]);

            foreach ($lines as $row) {
                $pi  = $poItems[$row['purchase_item_id']];
                $qty = (int)$row['qty_received'];

                $product = $productMap[$pi->product_id]; // sudah dicek di atas

                $grItem = GoodsReceiptItem::create([
                    'goods_receipt_id' => $gr->id,
                    'purchase_item_id' => $pi->id,
                    'qty_received'     => $qty,
                    'condition_notes'  => $row['condition_notes'] ?? null,
                ]);

                $pi->qty_received += $qty;
                $pi->save();

                // Layer + stock_ledger + products.stock sync all happen inside
                // the service. GR carries a real purchase price, so that is the
                // cost basis here.
                app(InventoryService::class)->addInboundLayer([
                    'product_id'        => $pi->product_id,
                    'qty'               => (float)$qty,
                    'unit_buy'          => (float)$pi->unit_price,
                    'unit_tax'          => 0,
                    'unit_other_cost'   => 0,
                    'store_location_id' => $poStoreId,
                    'source_type'       => 'GR',
                    'source_id'         => $grItem->id,
                    'user_id'           => $userId,
                    'note'              => "GR {$gr->gr_number}",
                ]);
            }

            $stillOpen = PurchaseItem::where('purchase_id',$purchase->id)
                          ->active()
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
