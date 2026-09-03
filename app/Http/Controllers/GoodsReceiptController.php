<?php

namespace App\Http\Controllers;

use App\Http\Requests\CostAdjustmentRequest;
use App\Http\Requests\FlagGoodsReceiptReviewRequest;
use App\Http\Requests\VoidGoodsReceiptRequest;
use App\Models\GoodsReceipt;
use App\Services\GrPoLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class GoodsReceiptController extends Controller
{
    use Concerns\AuthorizesStoreAccess;

    public function index(Request $r)
    {
        $q = GoodsReceipt::with([
            'purchase.supplier:id,name',
            'purchase:id,purchase_number,supplier_id,store_location_id',
            'reviewFlaggedBy:id,name',
        ])
            ->when($r->status, function ($qq, $v) {
                if ($v === 'reversed') {
                    $qq->whereNotNull('reversed_at');
                } else {
                    $qq->where('status', $v)->whereNull('reversed_at');
                }
            })
            ->when($r->purchase_id, fn ($qq, $v) => $qq->where('purchase_id', (int) $v))
            ->when($r->from, fn ($qq, $v) => $qq->whereDate('received_date', '>=', $v))
            ->when($r->to, fn ($qq, $v) => $qq->whereDate('received_date', '<=', $v));

        $flagged = $r->boolean('review_flagged')
            && Schema::hasColumn('goods_receipts', 'review_flagged_at');
        if ($flagged) {
            $q->whereNotNull('review_flagged_at')->orderByDesc('review_flagged_at');
        } else {
            $q->orderByDesc('id');
        }

        $this->applyPurchaseStoreScope($q, $r);

        return response()->json($q->paginate(min(100, (int) ($r->per_page ?? 15))));
    }

    public function show(Request $r, GoodsReceipt $goodsReceipt)
    {
        $this->authorizeGr($r, $goodsReceipt);
        $goodsReceipt->load([
            'purchase:id,purchase_number,supplier_id,store_location_id',
            'purchase.supplier:id,name',
            'items.purchaseItem:id,purchase_id,product_id,qty_order,qty_received,unit_price',
            'items.purchaseItem.product:id,sku,name',
        ]);

        $lifecycle = app(GrPoLifecycleService::class)->inspect($goodsReceipt);
        $goodsReceipt->setAttribute('lifecycle', $lifecycle);

        return $goodsReceipt;
    }

    public function void(VoidGoodsReceiptRequest $r, GoodsReceipt $goodsReceipt)
    {
        $this->authorizeGr($r, $goodsReceipt);

        $result = app(GrPoLifecycleService::class)->void(
            $goodsReceipt,
            $r->user(),
            $r->validated()['reason'] ?? null
        );

        return response()->json([
            'message' => $result['message'],
            'action' => $result['action'],
            'gr' => $result['goods_receipt'],
            'lifecycle' => $result['lifecycle'] ?? null,
        ]);
    }

    public function costAdjust(CostAdjustmentRequest $r, GoodsReceipt $goodsReceipt)
    {
        $this->authorizeGr($r, $goodsReceipt);

        $result = app(GrPoLifecycleService::class)->costAdjust(
            $goodsReceipt,
            $r->user(),
            $r->validated()
        );

        return response()->json([
            'message' => 'Cost adjustment posted. Original sale/transfer records were not changed.',
            'adjustments' => $result['adjustments'],
            'lifecycle' => $result['lifecycle'],
        ], 201);
    }

    public function flagReview(FlagGoodsReceiptReviewRequest $r, GoodsReceipt $goodsReceipt)
    {
        $this->authorizeGr($r, $goodsReceipt);

        $gr = app(GrPoLifecycleService::class)->flagReview(
            $goodsReceipt,
            $r->user(),
            $r->validated()['reason']
        );
        $gr->setAttribute('lifecycle', app(GrPoLifecycleService::class)->inspect($gr));

        return response()->json([
            'message' => 'GR ditandai untuk manual review. Tidak ada perubahan stok otomatis.',
            'gr' => $gr,
        ]);
    }

    public function resolveReview(Request $r, GoodsReceipt $goodsReceipt)
    {
        $this->authorizeGr($r, $goodsReceipt);

        $gr = app(GrPoLifecycleService::class)->resolveReview($goodsReceipt);
        $gr->setAttribute('lifecycle', app(GrPoLifecycleService::class)->inspect($gr));

        return response()->json([
            'message' => 'Flag manual review dilepas.',
            'gr' => $gr,
        ]);
    }

    protected function authorizeGr(Request $r, GoodsReceipt $goodsReceipt): void
    {
        $goodsReceipt->loadMissing('purchase:id,store_location_id');
        $storeId = $goodsReceipt->purchase?->store_location_id
            ? (int) $goodsReceipt->purchase->store_location_id
            : null;
        $this->authorizeStoreAccess($r->user(), $storeId);
    }

    /**
     * Scope GRs to the selected / allowed stores via their purchase.
     */
    protected function applyPurchaseStoreScope($query, Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $storeIds = [];
        if ($request->filled('store_location_ids')) {
            $raw = $request->input('store_location_ids');
            if (is_string($raw)) {
                $raw = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
            }
            if (is_array($raw)) {
                foreach ($raw as $id) {
                    $n = (int) $id;
                    if ($n > 0) {
                        $storeIds[] = $n;
                    }
                }
            }
        }

        if ($storeIds === []) {
            $storeId = null;
            if ($request->filled('store_location_id')) {
                $storeId = (int) $request->input('store_location_id');
            } elseif ($request->filled('store_id')) {
                $storeId = (int) $request->input('store_id');
            }
            if ($storeId !== null) {
                $storeIds = [$storeId];
            }
        }

        $storeIds = array_values(array_unique($storeIds));

        if ($storeIds !== []) {
            foreach ($storeIds as $sid) {
                $this->authorizeStoreAccess($user, $sid);
            }

            return $query->whereHas('purchase', function ($pq) use ($storeIds) {
                if (count($storeIds) === 1) {
                    $pq->where('store_location_id', $storeIds[0]);
                } else {
                    $pq->whereIn('store_location_id', $storeIds);
                }
            });
        }

        if ($user->isAdmin()) {
            return $query;
        }

        $allowed = $user->allowedStoreIds();
        if ($allowed === null) {
            return $query;
        }
        if ($allowed === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('purchase', function ($pq) use ($allowed) {
            $pq->whereIn('store_location_id', $allowed);
        });
    }
}
