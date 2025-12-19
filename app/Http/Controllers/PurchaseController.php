<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\PurchaseReceiveRequest; // kalau kamu pakai ini di tempat lain
use App\Models\{Purchase, PurchaseItem, Product};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    public function index(Request $r)
    {
        $perPage = (int) ($r->per_page ?? 10);
        $perPage = $perPage > 100 ? 100 : $perPage;

        $search = trim((string) $r->search);

        $q = Purchase::query()
            ->select([
                'purchases.id',
                'purchases.purchase_number',
                'purchases.supplier_id',
                'purchases.user_id',
                'purchases.store_location_id',
                'purchases.order_date',
                'purchases.expected_date',
                'purchases.status',
                'purchases.approved_by',
                'purchases.approved_at',
                'purchases.subtotal',
                'purchases.tax_total',
                'purchases.other_cost',
                'purchases.grand_total',
                'purchases.created_at',
            ])
            ->with([
                'supplier:id,name',
                'user:id,name,store_location_id',
            ])
            ->withCount('items')
            ->selectSub(
                DB::table('purchase_items')
                    ->selectRaw('COALESCE(SUM(qty_order),0)')
                    ->whereColumn('purchase_items.purchase_id', 'purchases.id'),
                'qty_total'
            )
            ->when($r->status, function ($qq, $v) {
                $qq->where('purchases.status', $v);
            })
            ->when($r->supplier_id, function ($qq, $v) {
                $qq->where('purchases.supplier_id', $v);
            })
            ->when($r->from, function ($qq, $v) {
                $qq->whereDate('purchases.order_date', '>=', $v);
            })
            ->when($r->to, function ($qq, $v) {
                $qq->whereDate('purchases.order_date', '<=', $v);
            })
            ->when($r->store_location_id, function ($qq, $v) {
                $qq->where('purchases.store_location_id', $v);
            })
            ->when($search !== '', function ($qq) use ($search) {
                $qq->where(function ($sub) use ($search) {
                    $sub->where('purchases.purchase_number', 'like', "%{$search}%")
                        ->orWhereHas('supplier', function ($qs) use ($search) {
                            $qs->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('purchases.id');

        $p = $q->paginate($perPage)->appends($r->query());

        return response()->json([
            'items' => $p->items(),
            'meta'  => [
                'current_page' => $p->currentPage(),
                'per_page'     => $p->perPage(),
                'last_page'    => $p->lastPage(),
                'total'        => $p->total(),
            ],
            'links' => [
                'next' => $p->nextPageUrl(),
                'prev' => $p->previousPageUrl(),
            ],
        ]);
    }

    public function show(Purchase $purchase)
    {
        return $purchase->load([
            'supplier:id,name',
            'storeLocation:id,name',
            'items.product:id,sku,name',
        ]);
    }

    public function store(StorePurchaseRequest $req)
    {
        $data   = $req->validated();
        $user   = $req->user();
        $userId = $user->id;

        $storeLocationId = $user->store_location_id ?? $user->store_location?->id ?? null;

        $po = DB::transaction(function () use ($data, $userId, $storeLocationId) {
            $po = Purchase::create([
                'purchase_number'    => Purchase::nextNumber(),
                'supplier_id'        => $data['supplier_id'],
                'user_id'            => $userId,
                'store_location_id'  => $storeLocationId,
                'order_date'         => $data['order_date'],
                'expected_date'      => $data['expected_date'] ?? null,
                'status'             => 'draft',
                'notes'              => $data['notes'] ?? null,
                'other_cost'         => (float) ($data['other_cost'] ?? 0),
                'subtotal'           => 0,
                'tax_total'          => 0,
                'grand_total'        => 0,
            ]);

            $subtotal = 0;
            $taxTotal = 0;

            foreach ($data['items'] as $row) {
                $product = Product::find($row['product_id']);
                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => "Product ID {$row['product_id']} tidak ditemukan.",
                    ]);
                }

                // ❗ LARANG NON-STOCK MASUK PO
                if (! $product->isStockTracked()) {
                    throw ValidationException::withMessages([
                        'items' => "Produk '{$product->name}' adalah non-stock dan tidak boleh dimasukkan ke Purchase Order.",
                    ]);
                }

                $qty       = (float) $row['qty_order'];
                $unitPrice = (float) $row['unit_price'];
                $discount  = (float) ($row['discount'] ?? 0);
                $tax       = (float) ($row['tax'] ?? 0);

                $lineTotal = ($qty * $unitPrice) - $discount + $tax;

                PurchaseItem::create([
                    'purchase_id'  => $po->id,
                    'product_id'   => $row['product_id'],
                    'qty_order'    => $qty,
                    'qty_received' => 0,
                    'unit_price'   => $unitPrice,
                    'discount'     => $discount,
                    'tax'          => $tax,
                    'line_total'   => $lineTotal,
                ]);

                $subtotal += ($qty * $unitPrice) - $discount;
                $taxTotal += $tax;
            }

            $otherCost  = (float) ($data['other_cost'] ?? 0);
            $grandTotal = $subtotal + $taxTotal + $otherCost;

            $po->update([
                'subtotal'    => $subtotal,
                'tax_total'   => $taxTotal,
                'grand_total' => $grandTotal,
            ]);

            return $po->load([
                'supplier:id,name',
                'storeLocation:id,name',
                'items.product:id,sku,name',
            ]);
        });

        return response()->json($po, 201);
    }

    public function approve(Request $r, Purchase $purchase)
    {
        if ($purchase->status !== 'draft') {
            return response()->json(['message' => 'Only draft can be approved'], 422);
        }
        if (!$purchase->items()->exists()) {
            return response()->json(['message' => 'No items to approve'], 422);
        }

        $purchase->update([
            'status'      => 'approved',
            'approved_by' => $r->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'message' => 'Purchase approved',
            'status'  => 'approved',
        ]);
    }

    public function cancel(Purchase $purchase)
    {
        if (in_array($purchase->status, ['closed', 'partially_received'])) {
            return response()->json(['message' => 'Cannot cancel received PO'], 422);
        }

        $purchase->update(['status' => 'canceled']);

        return response()->json(['message' => 'Purchase canceled']);
    }

    public function batch(Request $r)
    {
        $ids = array_filter(
            (array) $r->input('ids', []),
            fn ($v) => is_numeric($v)
        );

        if (empty($ids)) {
            return response()->json(['items' => []]);
        }

        $rows = Purchase::with([
            'supplier:id,name',
            'storeLocation:id,name',
            'items.product:id,sku,name',
        ])->whereIn('id', $ids)->get();

        return response()->json(['items' => $rows]);
    }
}
