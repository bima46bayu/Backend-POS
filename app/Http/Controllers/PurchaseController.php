<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\PurchaseReceiveRequest; // kalau kamu pakai ini di tempat lain
use App\Models\{Purchase, PurchaseItem};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    /**
     * LIST PO (ringan & cepat)
     * - Tidak meload items.product (berat) di list
     * - Menyertakan supplier & store_location (id,name)
     * - Menyertakan items_count dan qty_total via aggregate
     * - Payload dinormalisasi: { items, meta, links }
     */
    // app/Http/Controllers/PurchaseController.php

    public function index(Request $r)
    {
        $perPage = (int) ($r->per_page ?? 10);
        $perPage = $perPage > 100 ? 100 : $perPage;

        $search = trim((string) $r->search);

        $q = Purchase::query()
            // pilih kolom yang diperlukan saja (PASTIKAN ada store_location_id)
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
            // supplier & user ringan
            ->with([
                'supplier:id,name',
                'user:id,name,store_location_id', // optional tapi enak buat debug
            ])
            // jumlah baris item per PO
            ->withCount('items')
            // total qty order (opsional)
            ->selectSub(
                DB::table('purchase_items')
                    ->selectRaw('COALESCE(SUM(qty_order),0)')
                    ->whereColumn('purchase_items.purchase_id', 'purchases.id'),
                'qty_total'
            )
            // ===== FILTER DARI FE =====
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
            // FILTER STORE DARI FE (PENTING)
            ->when($r->store_location_id, function ($qq, $v) {
                $qq->where('purchases.store_location_id', $v);
            })
            // SEARCH: nomor PO / nama supplier
            ->when($search !== '', function ($qq) use ($search) {
                $qq->where(function ($sub) use ($search) {
                    $sub->where('purchases.purchase_number', 'like', "%{$search}%")
                        ->orWhereHas('supplier', function ($qs) use ($search) {
                            $qs->where('name', 'like', "%{$search}%");
                        });
                });
            })
            // sort
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


    /**
     * DETAIL PO (panggil hanya saat user buka modal/halaman detail)
     * Tetap lengkap dengan items.product.
     */
    public function show(Purchase $purchase)
    {
        return $purchase->load([
            'supplier:id,name',
            'storeLocation:id,name',
            'items.product:id,sku,name',
        ]);
    }

    /**
     * CREATE PO (draft)
     */
    public function store(StorePurchaseRequest $req)
    {
        $data   = $req->validated();
        $user   = $req->user();
        $userId = $user->id;

        // AMBIL STORE USER (sesuai pola project-mu yang lain)
        $storeLocationId = $user->store_location_id ?? $user->store_location?->id ?? null;

        $po = DB::transaction(function () use ($data, $userId, $storeLocationId) {
            $po = Purchase::create([
                'purchase_number'    => Purchase::nextNumber(),
                'supplier_id'        => $data['supplier_id'],
                'user_id'            => $userId,
                'store_location_id'  => $storeLocationId,   // <-- PENTING
                'order_date'         => $data['order_date'],
                'expected_date'      => $data['expected_date'] ?? null,
                'status'             => 'draft',
                'notes'              => $data['notes'] ?? null,
                'other_cost'         => (float) ($data['other_cost'] ?? 0),
            ]);

            // ... sisanya sama seperti punyamu (loop items, hitung subtotal, dll)

            return $po->load('items.product:id,sku,name');
        });

        return response()->json($po, 201);
    }


    /**
     * APPROVE PO
     */
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

    /**
     * CANCEL PO
     */
    public function cancel(Purchase $purchase)
    {
        if (in_array($purchase->status, ['closed', 'partially_received'])) {
            return response()->json(['message' => 'Cannot cancel received PO'], 422);
        }

        $purchase->update(['status' => 'canceled']);

        return response()->json(['message' => 'Purchase canceled']);
    }

    /**
     * (OPSIONAL) BATCH DETAIL
     * Ambil banyak detail sekaligus: GET /api/purchases/batch?ids[]=1&ids[]=2
     * Pakai jika memang perlu prefetch detail untuk beberapa baris—lebih hemat daripada N request.
     */
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
