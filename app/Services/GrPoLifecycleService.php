<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CostAdjustment;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Support\StockLedgerWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Encodes the GR / PO FIFO lifecycle rules:
 *
 * - Cost layers are append-only once consumed.
 * - PO price edits never rewrite a snapshotted layer.
 * - Corrections are additive (GR_REVERSAL, cost_adjustment).
 */
class GrPoLifecycleService
{
    public const ACTION_DELETE = 'delete';
    public const ACTION_REVERSE = 'reverse';
    public const ACTION_COST_ADJUSTMENT = 'cost_adjustment';
    public const ACTION_MANUAL_REVIEW = 'manual_review';
    public const ACTION_NONE = 'none';

    private const EPS = 1e-9;

    public function priceEditState(Purchase $purchase): array
    {
        $status = strtolower((string) $purchase->status);
        if (in_array($status, ['canceled', 'cancelled'], true)) {
            return [
                'editable' => false,
                'code' => 'PO_CANCELED',
                'message' => 'PO yang dibatalkan tidak bisa diubah.',
                'recommended_action' => null,
            ];
        }

        $receipts = $purchase->goodsReceipts()
            ->whereIn('status', ['posted', 'reversed', 'draft'])
            ->get();

        if ($receipts->isEmpty()) {
            return [
                'editable' => true,
                'code' => null,
                'message' => null,
                'recommended_action' => null,
            ];
        }

        $anyConsumed = false;
        foreach ($receipts as $gr) {
            $info = $this->inspect($gr);
            if (($info['qty_consumed'] ?? 0) > self::EPS) {
                $anyConsumed = true;
                break;
            }
        }

        return [
            'editable' => false,
            'code' => 'PO_HAS_GR',
            'message' => $anyConsumed
                ? 'Harga satuan PO tetap seperti di order dan tidak diubah. Koreksi harga stok yang sudah terjual memakai Cost Adjustment di Riwayat GR (selisih COGS), bukan edit harga PO.'
                : 'Harga PO tidak bisa diubah setelah GR. Hapus GR yang belum terpakai, lalu masukkan ulang, atau gunakan Cost Adjustment.',
            'recommended_action' => $anyConsumed
                ? self::ACTION_COST_ADJUSTMENT
                : self::ACTION_DELETE,
        ];
    }

    /**
     * PO-facing explanation of GR reverse / cost adjustment without rewriting the order.
     */
    public function purchaseStory(Purchase $purchase): array
    {
        $receipts = $purchase->goodsReceipts()
            ->with('items')
            ->orderByDesc('id')
            ->get();

        $itemStats = [];
        foreach ($purchase->items as $item) {
            $itemStats[(int) $item->id] = [
                'purchase_item_id' => (int) $item->id,
                'product_id' => (int) $item->product_id,
                'qty_order' => (float) $item->qty_order,
                'qty_received' => (float) $item->qty_received,
                'qty_reversed' => 0.0,
                'po_unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'adjusted_unit_cost' => null,
                'cogs_delta' => 0.0,
            ];
        }

        $receiptRows = [];
        $qtyReversed = 0.0;
        foreach ($receipts as $gr) {
            $grReceived = 0.0;
            $grReversed = 0.0;
            foreach ($gr->items as $grItem) {
                $grReceived += (float) $grItem->qty_received;
                $reversed = (float) ($grItem->qty_reversed ?? 0);
                $grReversed += $reversed;
                $pid = (int) $grItem->purchase_item_id;
                if (isset($itemStats[$pid])) {
                    $itemStats[$pid]['qty_reversed'] += $reversed;
                }
            }
            $qtyReversed += $grReversed;
            $receiptRows[] = [
                'id' => (int) $gr->id,
                'gr_number' => $gr->gr_number,
                'status' => $gr->reversed_at ? 'reversed' : $gr->status,
                'received_date' => optional($gr->received_date)?->toDateString(),
                'qty_received' => $grReceived,
                'qty_reversed' => $grReversed,
                'cost_adjustment_count' => 0,
            ];
        }

        $adjustmentRows = [];
        $cogsDeltaTotal = 0.0;
        $latestAdjByProduct = [];
        $cogsDeltaByProduct = [];
        $grIds = $receipts->pluck('id')->all();
        if ($grIds !== [] && Schema::hasTable('cost_adjustments')) {
            $adjModels = CostAdjustment::query()
                ->whereIn('goods_receipt_id', $grIds)
                ->orderByDesc('id')
                ->get();

            $counts = $adjModels->groupBy(fn (CostAdjustment $row) => (int) $row->goods_receipt_id)
                ->map->count();
            foreach ($receiptRows as &$row) {
                $row['cost_adjustment_count'] = (int) ($counts->get($row['id']) ?? 0);
            }
            unset($row);

            $productIds = $adjModels->pluck('product_id')->unique()->filter()->values()->all();
            $productMap = $productIds === []
                ? collect()
                : Product::query()->whereIn('id', $productIds)->get(['id', 'sku', 'name'])->keyBy('id');

            foreach ($adjModels as $adj) {
                $product = $productMap->get((int) $adj->product_id);
                $cogsDeltaTotal += (float) $adj->cogs_delta;
                $productId = (int) $adj->product_id;
                $cogsDeltaByProduct[$productId] = ($cogsDeltaByProduct[$productId] ?? 0) + (float) $adj->cogs_delta;
                if (! isset($latestAdjByProduct[$productId])) {
                    $latestAdjByProduct[$productId] = (float) $adj->new_unit_cost;
                }
                $adjustmentRows[] = [
                    'id' => (int) $adj->id,
                    'goods_receipt_id' => (int) $adj->goods_receipt_id,
                    'product_id' => $productId,
                    'product_sku' => $product?->sku,
                    'product_name' => $product?->name,
                    'qty_affected' => (float) $adj->qty_affected,
                    'old_unit_cost' => (float) $adj->old_unit_cost,
                    'new_unit_cost' => (float) $adj->new_unit_cost,
                    'cogs_delta' => (float) $adj->cogs_delta,
                    'reason' => $adj->reason,
                    'created_at' => optional($adj->created_at)?->toIso8601String(),
                ];
            }
        }

        foreach ($itemStats as &$stat) {
            $stat['adjusted_unit_cost'] = $latestAdjByProduct[$stat['product_id']] ?? null;
            $stat['cogs_delta'] = $cogsDeltaByProduct[$stat['product_id']] ?? 0.0;
        }
        unset($stat);

        $hasReversed = $qtyReversed > self::EPS
            || $receipts->contains(fn ($gr) => $gr->reversed_at !== null);
        $hasAdj = $adjustmentRows !== [];
        $qtyReceivedKept = (float) $purchase->items->sum('qty_received');

        return [
            'has_gr' => $receipts->isNotEmpty(),
            'has_reversed' => $hasReversed,
            'has_cost_adjustment' => $hasAdj,
            'qty_received' => $qtyReceivedKept,
            'qty_reversed' => $qtyReversed,
            'cost_adjustment_count' => count($adjustmentRows),
            'cogs_delta_total' => $cogsDeltaTotal,
            'headline' => $this->storyHeadline($hasReversed, $hasAdj),
            'message' => $this->storyMessage($hasReversed, $hasAdj),
            'receipts' => $receiptRows,
            'items' => array_values($itemStats),
            'cost_adjustments' => $adjustmentRows,
        ];
    }

    private function storyHeadline(bool $hasReversed, bool $hasAdj): ?string
    {
        if ($hasReversed && $hasAdj) {
            return 'PO tetap seperti order. GR di-reverse; koreksi harga ada di Cost Adjustment.';
        }
        if ($hasReversed) {
            return 'PO tetap seperti order. Sebagian GR sudah di-reverse.';
        }
        if ($hasAdj) {
            return 'PO tetap seperti order. Koreksi harga stok terjual ada di Cost Adjustment.';
        }

        return null;
    }

    private function storyMessage(bool $hasReversed, bool $hasAdj): ?string
    {
        if ($hasReversed && $hasAdj) {
            return 'Harga satuan, Line Total, dan total PO tidak diubah (qty order × harga PO). Qty Received adalah stok yang masih tercatat (termasuk yang sudah terjual). Qty yang di-reverse kembali ke Remain dan bisa di-Receive lagi. Koreksi harga stok terjual tercatat sebagai selisih COGS di Riwayat GR, bukan pengurang Line Total.';
        }
        if ($hasReversed) {
            return 'Harga satuan dan Line Total PO tetap seperti di order. Qty yang di-reverse tidak lagi dihitung sebagai Received — sisa order bisa di-Receive lagi. Qty yang sudah terjual tetap tercatat di Received karena tidak bisa di-unreceive.';
        }
        if ($hasAdj) {
            return 'Harga satuan dan Line Total PO tetap qty order × harga PO. Koreksi harga stok yang sudah terjual memakai Cost Adjustment di Riwayat GR (selisih COGS), bukan edit Line Total.';
        }

        return null;
    }

    /**
     * Same untouched/consumed check as GR delete, scoped to one PO line.
     */
    public function inspectPurchaseItem(PurchaseItem $item, ?Purchase $purchase = null): array
    {
        $purchase = $purchase ?: $item->purchase;
        $poStatus = strtolower((string) ($purchase?->status ?? ''));
        $lineStatus = strtolower((string) ($item->status ?? 'open'));

        $empty = [
            'qty_received' => 0.0,
            'qty_remaining' => 0.0,
            'qty_reversed' => 0.0,
            'qty_consumed' => 0.0,
            'gr_numbers' => [],
        ];

        if (in_array($lineStatus, ['cancelled', 'canceled'], true)) {
            return array_merge($empty, [
                'deletable' => false,
                'code' => 'LINE_CANCELLED',
                'action' => self::ACTION_NONE,
                'recommended_action' => null,
                'message' => 'Baris PO ini sudah dibatalkan.',
            ]);
        }

        if (in_array($poStatus, ['canceled', 'cancelled'], true)) {
            return array_merge($empty, [
                'deletable' => false,
                'code' => 'PO_CANCELED',
                'action' => self::ACTION_NONE,
                'recommended_action' => null,
                'message' => 'PO yang dibatalkan tidak bisa diubah.',
            ]);
        }

        $grItems = GoodsReceiptItem::query()
            ->with('goodsReceipt:id,gr_number,purchase_id')
            ->where('purchase_item_id', $item->id)
            ->get();

        $qtyLayerReceived = 0.0;
        $qtyRemaining = 0.0;
        $qtyReversed = 0.0;
        $qtyConsumed = 0.0;
        $grNumbers = [];
        $layerIds = [];

        foreach ($grItems as $grItem) {
            if ($grItem->goodsReceipt?->gr_number) {
                $grNumbers[] = $grItem->goodsReceipt->gr_number;
            }
            foreach ($this->layersForItem($grItem) as $layer) {
                $initial = (float) ($layer->qty_initial ?? 0);
                $remaining = (float) ($layer->qty_remaining ?? 0);
                $reversed = (float) ($layer->qty_reversed ?? 0);
                $qtyLayerReceived += $initial;
                $qtyRemaining += $remaining;
                $qtyReversed += $reversed;
                $qtyConsumed += max(0, $initial - $remaining - $reversed);
                $layerIds[] = (int) $layer->id;
            }
        }

        $hasAdjustment = false;
        if ($layerIds !== [] && Schema::hasTable('cost_adjustments')) {
            $grItemIds = $grItems->pluck('id')->all();
            $hasAdjustment = CostAdjustment::query()
                ->where(function ($q) use ($layerIds, $grItemIds) {
                    $q->whereIn('layer_id', $layerIds);
                    if ($grItemIds !== []) {
                        $q->orWhereIn('goods_receipt_item_id', $grItemIds);
                    }
                })
                ->exists();
        }

        $blocked = $qtyConsumed > self::EPS || $qtyReversed > self::EPS || $hasAdjustment;
        $grNumbers = array_values(array_unique($grNumbers));

        $base = [
            'qty_received' => $qtyLayerReceived,
            'qty_remaining' => $qtyRemaining,
            'qty_reversed' => $qtyReversed,
            'qty_consumed' => $qtyConsumed,
            'gr_numbers' => $grNumbers,
        ];

        if ($blocked) {
            $action = $qtyRemaining > self::EPS
                ? self::ACTION_REVERSE
                : self::ACTION_COST_ADJUSTMENT;

            return array_merge($base, [
                'deletable' => false,
                'code' => 'LINE_CONSUMED',
                'action' => $action,
                'recommended_action' => $action,
                'message' => $action === self::ACTION_REVERSE
                    ? 'Stok baris ini sudah terpakai sebagian. Reverse sisa stok di Riwayat GR, atau Cost Adjustment untuk qty yang sudah terjual. Baris PO tidak bisa dihapus.'
                    : 'Stok baris ini sudah terpakai. Gunakan Cost Adjustment di Riwayat GR. Baris PO tidak bisa dihapus.',
            ]);
        }

        $hasGr = $grItems->isNotEmpty();

        return array_merge($base, [
            'deletable' => true,
            'code' => $hasGr ? 'LINE_UNTOUCHED' : 'LINE_NO_GR',
            'action' => self::ACTION_DELETE,
            'recommended_action' => self::ACTION_DELETE,
            'message' => $hasGr
                ? 'Baris bisa dibatalkan. GR/layer belum terpakai dan akan dihapus, stok kembali 0.'
                : 'Baris bisa dibatalkan. Belum ada GR.',
        ]);
    }

    /**
     * Soft-cancel a PO line. Untouched GR layers are hard-deleted; consumed lines are blocked.
     */
    public function cancelPurchaseItem(Purchase $purchase, PurchaseItem $item, User $user): array
    {
        return DB::transaction(function () use ($purchase, $item, $user) {
            $lockedPo = Purchase::where('id', $purchase->id)->lockForUpdate()->first();
            $locked = PurchaseItem::where('id', $item->id)
                ->where('purchase_id', $purchase->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $lockedPo) {
                throw ValidationException::withMessages([
                    'item' => 'Baris PO tidak ditemukan.',
                ]);
            }

            $state = $this->inspectPurchaseItem($locked, $lockedPo);
            if (! ($state['deletable'] ?? false)) {
                abort(response()->json([
                    'message' => $state['message'],
                    'code' => $state['code'],
                    'action' => $state['recommended_action'],
                    'recommended_action' => $state['recommended_action'],
                    'line' => $state,
                ], 422));
            }

            $removed = $this->hardDeleteUntouchedLayersForPurchaseItem($locked);
            $grLabel = $removed['gr_numbers'] !== []
                ? implode('/', $removed['gr_numbers'])
                : null;

            $locked->qty_received = 0;
            if (Schema::hasColumn('purchase_items', 'status')) {
                $locked->status = 'cancelled';
                $locked->cancelled_at = now();
                $locked->cancelled_by = $user->id;
                $locked->cancelled_note = $grLabel
                    ? "PO line cancelled, {$grLabel}/layer removed, stock adjusted to 0"
                    : 'PO line cancelled';
            }
            $locked->save();

            $lockedPo->load('items');
            $this->recalculatePurchaseTotals($lockedPo);
            $this->refreshPurchaseReceiveStatus($lockedPo->fresh());

            $freshPo = $lockedPo->fresh([
                'supplier:id,name',
                'storeLocation:id,name',
                'items.product:id,sku,name,unit_id',
                'items.product.unit:id,name',
            ]);

            $activeLeft = $freshPo->items->contains(fn (PurchaseItem $row) => ! $row->isCancelled());
            if (! $activeLeft) {
                $freshPo->status = 'canceled';
                $freshPo->save();
            }

            foreach ($removed['product_stores'] as $row) {
                InventoryService::syncLegacyProductStock($row['product_id'], $row['store_id']);
            }

            $message = $locked->cancelled_note ?: 'PO line cancelled';
            $this->logLineCancel($user, $lockedPo, $locked, $message);

            return [
                'message' => $message,
                'purchase' => $freshPo->fresh([
                    'supplier:id,name',
                    'storeLocation:id,name',
                    'items.product:id,sku,name,unit_id',
                    'items.product.unit:id,name',
                ]),
                'item' => $locked->fresh(),
            ];
        });
    }

    public function updatePurchasePrices(Purchase $purchase, array $payload, User $user): Purchase
    {
        $state = $this->priceEditState($purchase);
        $priceFieldsPresent = ! empty($payload['items'])
            || array_key_exists('other_cost', $payload);

        if ($priceFieldsPresent && ! $state['editable']) {
            throw ValidationException::withMessages([
                'unit_price' => $state['message'] ?? 'Harga PO tidak bisa diubah.',
            ]);
        }

        return DB::transaction(function () use ($purchase, $payload) {
            $locked = Purchase::where('id', $purchase->id)->lockForUpdate()->first();

            if (array_key_exists('notes', $payload)) {
                $locked->notes = $payload['notes'];
            }
            if (array_key_exists('expected_date', $payload)) {
                $locked->expected_date = $payload['expected_date'];
            }
            if (array_key_exists('order_date', $payload)) {
                $locked->order_date = $payload['order_date'];
            }
            if (array_key_exists('other_cost', $payload)) {
                $locked->other_cost = (float) $payload['other_cost'];
            }
            $locked->save();

            foreach ($payload['items'] ?? [] as $row) {
                $item = PurchaseItem::where('purchase_id', $locked->id)
                    ->where('id', (int) $row['id'])
                    ->lockForUpdate()
                    ->first();
                if (! $item) {
                    throw ValidationException::withMessages([
                        'items' => "Purchase item {$row['id']} tidak ditemukan.",
                    ]);
                }
                if ($item->isCancelled()) {
                    continue;
                }

                if (array_key_exists('unit_price', $row)) {
                    $item->unit_price = (float) $row['unit_price'];
                }
                if (array_key_exists('discount', $row)) {
                    $item->discount = (float) $row['discount'];
                }
                if (array_key_exists('tax', $row)) {
                    $item->tax = (float) $row['tax'];
                }

                $item->line_total = ($item->qty_order * $item->unit_price)
                    - $item->discount
                    + $item->tax;
                $item->save();
            }

            $locked->load('items');
            $this->recalculatePurchaseTotals($locked);

            return $locked->fresh([
                'supplier:id,name',
                'storeLocation:id,name',
                'items.product:id,sku,name,unit_id',
                'items.product.unit:id,name',
            ]);
        });
    }

    public function inspect(GoodsReceipt $gr): array
    {
        $layers = $this->layersForGr($gr);

        $qtyReceived = 0.0;
        $qtyRemaining = 0.0;
        $qtyReversed = 0.0;
        $layerRows = [];

        $productMap = collect();
        $productIds = array_values(array_unique(array_map(
            fn ($layer) => (int) $layer->product_id,
            $layers
        )));
        if ($productIds !== []) {
            $productMap = Product::query()
                ->whereIn('id', $productIds)
                ->get(['id', 'sku', 'name'])
                ->keyBy('id');
        }

        $adjustmentsByLayer = collect();
        $adjustmentRows = [];
        if (Schema::hasTable('cost_adjustments')) {
            $adjModels = CostAdjustment::query()
                ->where('goods_receipt_id', $gr->id)
                ->orderBy('id')
                ->get();
            $adjustmentsByLayer = $adjModels->groupBy(fn ($row) => (int) $row->layer_id);
            $adjustmentRows = $adjModels->map(function (CostAdjustment $adj) use ($productMap) {
                $product = $productMap->get((int) $adj->product_id);

                return [
                    'id' => (int) $adj->id,
                    'layer_id' => $adj->layer_id ? (int) $adj->layer_id : null,
                    'product_id' => (int) $adj->product_id,
                    'product_sku' => $product?->sku,
                    'product_name' => $product?->name,
                    'qty_affected' => (float) $adj->qty_affected,
                    'old_unit_cost' => (float) $adj->old_unit_cost,
                    'new_unit_cost' => (float) $adj->new_unit_cost,
                    'cogs_delta' => (float) $adj->cogs_delta,
                    'reason' => $adj->reason,
                    'created_at' => optional($adj->created_at)?->toIso8601String(),
                ];
            })->all();
        }

        foreach ($layers as $layer) {
            $initial = (float) ($layer->qty_initial ?? 0);
            $remaining = (float) ($layer->qty_remaining ?? 0);
            $reversed = (float) ($layer->qty_reversed ?? 0);
            $consumed = max(0, $initial - $remaining - $reversed);
            $product = $productMap->get((int) $layer->product_id);
            $originalCost = (float) ($layer->unit_landed_cost ?? $layer->unit_cost ?? 0);
            $latestAdj = $adjustmentsByLayer->get((int) $layer->id)?->last();

            $qtyReceived += $initial;
            $qtyRemaining += $remaining;
            $qtyReversed += $reversed;

            $layerRows[] = [
                'id' => (int) $layer->id,
                'product_id' => (int) $layer->product_id,
                'product_sku' => $product?->sku,
                'product_name' => $product?->name,
                'goods_receipt_item_id' => $layer->source_id ? (int) $layer->source_id : null,
                'qty_received' => $initial,
                'qty_remaining' => $remaining,
                'qty_reversed' => $reversed,
                'qty_consumed' => $consumed,
                'unit_cost' => $originalCost,
                'adjusted_unit_cost' => $latestAdj
                    ? (float) $latestAdj->new_unit_cost
                    : $originalCost,
                'status' => $layer->status ?? 'open',
                'consumed_review_flagged' => (bool) ($layer->consumed_review_flagged ?? false),
            ];
        }

        $qtyConsumed = max(0, $qtyReceived - $qtyRemaining - $qtyReversed);
        $grStatus = strtolower((string) $gr->status);

        if ($layers === [] && in_array($grStatus, ['canceled', 'cancelled'], true)) {
            $action = self::ACTION_NONE;
            $allowed = [];
        } elseif ($qtyConsumed <= self::EPS && $qtyReversed <= self::EPS) {
            $action = self::ACTION_DELETE;
            $allowed = [self::ACTION_DELETE];
        } elseif ($qtyRemaining > self::EPS) {
            $action = self::ACTION_REVERSE;
            $allowed = [self::ACTION_REVERSE];
            if ($qtyConsumed > self::EPS) {
                $allowed[] = self::ACTION_COST_ADJUSTMENT;
            }
        } else {
            $action = self::ACTION_COST_ADJUSTMENT;
            $allowed = [self::ACTION_COST_ADJUSTMENT, self::ACTION_MANUAL_REVIEW];
        }

        return [
            'action' => $action,
            'allowed_actions' => $allowed,
            'qty_received' => $qtyReceived,
            'qty_remaining' => $qtyRemaining,
            'qty_reversed' => $qtyReversed,
            'qty_consumed' => $qtyConsumed,
            'review_flagged' => $gr->review_flagged_at !== null,
            'review_reason' => $gr->review_reason,
            'layers' => $layerRows,
            'cost_adjustments' => $adjustmentRows,
        ];
    }

    /**
     * GR delete requested — follows the PRD decision tree.
     *
     * @return array{action:string, goods_receipt:?GoodsReceipt, message:string}
     */
    public function void(GoodsReceipt $gr, User $user, ?string $reason = null): array
    {
        return DB::transaction(function () use ($gr, $user, $reason) {
            $locked = GoodsReceipt::where('id', $gr->id)->lockForUpdate()->first();
            if (! $locked) {
                throw ValidationException::withMessages(['gr' => 'Goods receipt tidak ditemukan.']);
            }

            $info = $this->inspect($locked);
            $action = $info['action'];

            if ($action === self::ACTION_DELETE) {
                $this->hardDelete($locked);

                return [
                    'action' => self::ACTION_DELETE,
                    'goods_receipt' => null,
                    'message' => 'GR dihapus. Layer belum terpakai, jadi dihapus permanen.',
                    'lifecycle' => $info,
                ];
            }

            if ($action === self::ACTION_REVERSE) {
                $reason = trim((string) $reason);
                if ($reason === '') {
                    throw ValidationException::withMessages([
                        'reason' => 'Alasan wajib diisi untuk reverse sisa stok GR.',
                    ]);
                }

                $updated = $this->reverseRemaining($locked, $user, $reason);
                $freshInfo = $this->inspect($updated);

                return [
                    'action' => self::ACTION_REVERSE,
                    'goods_receipt' => $updated,
                    'message' => 'Sisa stok GR di-reverse. Qty yang sudah terpakai tidak diubah dan ditandai untuk review.',
                    'lifecycle' => $freshInfo,
                ];
            }

            if ($action === self::ACTION_COST_ADJUSTMENT) {
                abort(response()->json([
                    'message' => 'Stok GR sudah terpakai seluruhnya. Tidak ada stok fisik untuk di-reverse. Gunakan Cost Adjustment untuk koreksi harga, atau flag Manual Review jika PO/GR salah.',
                    'code' => 'GR_FULLY_CONSUMED',
                    'action' => self::ACTION_COST_ADJUSTMENT,
                    'allowed_actions' => $info['allowed_actions'],
                    'lifecycle' => $info,
                ], 422));
            }

            throw ValidationException::withMessages([
                'gr' => 'GR ini tidak bisa dihapus atau di-reverse.',
            ]);
        });
    }

    public function costAdjust(GoodsReceipt $gr, User $user, array $payload): array
    {
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan wajib diisi untuk cost adjustment.',
            ]);
        }

        $lines = isset($payload['lines']) && is_array($payload['lines'])
            ? $payload['lines']
            : null;
        $globalCost = array_key_exists('new_unit_cost', $payload) && $payload['new_unit_cost'] !== null && $payload['new_unit_cost'] !== ''
            ? (float) $payload['new_unit_cost']
            : null;
        $layerId = isset($payload['layer_id']) ? (int) $payload['layer_id'] : null;
        $qtyOverride = isset($payload['qty_affected']) ? (float) $payload['qty_affected'] : null;

        if ($lines === null && $globalCost === null) {
            throw ValidationException::withMessages([
                'new_unit_cost' => 'Harga satuan baru wajib diisi per item.',
            ]);
        }

        return DB::transaction(function () use ($gr, $user, $reason, $lines, $globalCost, $layerId, $qtyOverride) {
            $locked = GoodsReceipt::where('id', $gr->id)->lockForUpdate()->first();
            $locked->loadMissing('purchase');
            $info = $this->inspect($locked);
            $targets = $info['layers'];

            if ($layerId) {
                $targets = array_values(array_filter(
                    $targets,
                    fn ($row) => (int) $row['id'] === $layerId
                ));
                if ($targets === []) {
                    throw ValidationException::withMessages([
                        'layer_id' => 'Layer tidak ditemukan pada GR ini.',
                    ]);
                }
            }

            $created = [];
            foreach ($targets as $row) {
                $consumed = (float) $row['qty_consumed'];
                if ($consumed <= self::EPS) {
                    continue;
                }

                $newCost = $this->resolveNewUnitCost($row, $lines, $globalCost);
                if ($newCost === null) {
                    continue;
                }

                $qty = ($qtyOverride !== null && $lines === null)
                    ? min($qtyOverride, $consumed)
                    : $consumed;
                if ($qty <= self::EPS) {
                    continue;
                }

                $oldCost = array_key_exists('adjusted_unit_cost', $row)
                    ? (float) $row['adjusted_unit_cost']
                    : (float) $row['unit_cost'];
                if (abs($newCost - $oldCost) < self::EPS) {
                    continue;
                }

                $delta = $qty * ($newCost - $oldCost);

                $originalMovementId = null;
                if (Schema::hasTable('stock_ledger')) {
                    $originalMovementId = DB::table('stock_ledger')
                        ->where('layer_id', $row['id'])
                        ->where('ref_type', 'GR')
                        ->orderBy('id')
                        ->value('id');
                }

                $adj = CostAdjustment::create([
                    'original_movement_id' => $originalMovementId,
                    'layer_id'             => $row['id'],
                    'goods_receipt_id'     => $locked->id,
                    'goods_receipt_item_id'=> $row['goods_receipt_item_id'],
                    'product_id'           => $row['product_id'],
                    'store_location_id'    => $locked->purchase?->store_location_id,
                    'qty_affected'         => $qty,
                    'old_unit_cost'        => $oldCost,
                    'new_unit_cost'        => $newCost,
                    'cogs_delta'           => $delta,
                    'reason'               => $reason,
                    'created_by'           => $user->id,
                ]);

                if (Schema::hasTable('stock_ledger')) {
                    StockLedgerWriter::write([
                        'product_id'        => $row['product_id'],
                        'direction'         => -1,
                        'qty'               => $qty,
                        'unit_cost'         => ($newCost - $oldCost),
                        'store_location_id' => $locked->purchase?->store_location_id,
                        'layer_id'          => $row['id'],
                        'user_id'           => $user->id,
                        'ref_type'          => 'COST_ADJUSTMENT',
                        'ref_id'            => $adj->id,
                        'note'              => $reason,
                    ]);
                }

                $created[] = $adj;
            }

            if ($created === []) {
                throw ValidationException::withMessages([
                    'new_unit_cost' => $lines !== null
                        ? 'Ubah harga satuan baru pada item yang dikoreksi. Item tanpa perubahan dilewati.'
                        : 'Tidak ada qty terpakai yang bisa di-adjust. Hapus GR yang belum terpakai, atau reverse sisa stok terlebih dahulu.',
                ]);
            }

            return [
                'adjustments' => $created,
                'lifecycle' => $this->inspect($locked->fresh()),
            ];
        });
    }

    private function resolveNewUnitCost(array $row, ?array $lines, ?float $globalCost): ?float
    {
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (! is_array($line) || ! array_key_exists('new_unit_cost', $line)) {
                    continue;
                }
                $lineLayer = isset($line['layer_id']) ? (int) $line['layer_id'] : 0;
                $lineItem = isset($line['goods_receipt_item_id']) ? (int) $line['goods_receipt_item_id'] : 0;
                if ($lineLayer > 0 && $lineLayer === (int) $row['id']) {
                    return (float) $line['new_unit_cost'];
                }
                if ($lineItem > 0 && $lineItem === (int) ($row['goods_receipt_item_id'] ?? 0)) {
                    return (float) $line['new_unit_cost'];
                }
            }

            return null;
        }

        return $globalCost;
    }

    public function flagReview(GoodsReceipt $gr, User $user, string $reason): GoodsReceipt
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan wajib diisi untuk manual review.',
            ]);
        }

        $gr->review_flagged_at = now();
        $gr->review_flagged_by = $user->id;
        $gr->review_reason = $reason;
        $gr->save();

        return $gr->fresh(['reviewFlaggedBy:id,name']);
    }

    public function resolveReview(GoodsReceipt $gr): GoodsReceipt
    {
        $gr->review_flagged_at = null;
        $gr->review_flagged_by = null;
        $gr->review_reason = null;
        $gr->save();

        return $gr->fresh();
    }

    private function hardDelete(GoodsReceipt $gr): void
    {
        $info = $this->inspect($gr);
        if (($info['qty_consumed'] ?? 0) > self::EPS || ($info['qty_reversed'] ?? 0) > self::EPS) {
            throw ValidationException::withMessages([
                'gr' => 'Layer yang sudah terpakai tidak boleh dihapus permanen.',
            ]);
        }

        $items = $gr->items()->lockForUpdate()->get();
        $productStores = [];

        foreach ($items as $item) {
            $layers = $this->layersForItem($item);
            foreach ($layers as $layer) {
                $consumed = (float) $layer->qty_initial
                    - (float) $layer->qty_remaining
                    - (float) ($layer->qty_reversed ?? 0);
                if ($consumed > self::EPS) {
                    throw ValidationException::withMessages([
                        'gr' => 'Layer yang sudah terpakai tidak boleh dihapus permanen.',
                    ]);
                }

                if (Schema::hasTable('inventory_consumptions')) {
                    DB::table('inventory_consumptions')->where('layer_id', $layer->id)->delete();
                }
                if (Schema::hasTable('stock_ledger')) {
                    DB::table('stock_ledger')->where('layer_id', $layer->id)->delete();
                }
                DB::table('inventory_layers')->where('id', $layer->id)->delete();

                $productStores[] = [
                    'product_id' => (int) $layer->product_id,
                    'store_id'   => $layer->store_location_id !== null ? (int) $layer->store_location_id : null,
                ];
            }

            $pi = PurchaseItem::where('id', $item->purchase_item_id)->lockForUpdate()->first();
            if ($pi) {
                $pi->qty_received = max(0, (float) $pi->qty_received - (float) $item->qty_received);
                $pi->save();
            }

            $item->delete();
        }

        $purchaseId = $gr->purchase_id;
        $gr->delete();

        $purchase = Purchase::find($purchaseId);
        if ($purchase) {
            $this->refreshPurchaseReceiveStatus($purchase);
        }

        foreach ($productStores as $row) {
            InventoryService::syncLegacyProductStock($row['product_id'], $row['store_id']);
        }
    }

    /**
     * @return array{product_stores: list<array{product_id:int,store_id:?int}>, gr_numbers: list<string>}
     */
    private function hardDeleteUntouchedLayersForPurchaseItem(PurchaseItem $item): array
    {
        $grItems = GoodsReceiptItem::query()
            ->with('goodsReceipt:id,gr_number')
            ->where('purchase_item_id', $item->id)
            ->lockForUpdate()
            ->get();

        $productStores = [];
        $grNumbers = [];
        $grIds = [];

        foreach ($grItems as $grItem) {
            foreach ($this->layersForItem($grItem) as $layer) {
                $consumed = (float) $layer->qty_initial
                    - (float) $layer->qty_remaining
                    - (float) ($layer->qty_reversed ?? 0);
                if ($consumed > self::EPS || (float) ($layer->qty_reversed ?? 0) > self::EPS) {
                    throw ValidationException::withMessages([
                        'item' => 'Layer yang sudah terpakai tidak boleh dihapus permanen.',
                    ]);
                }

                if (Schema::hasTable('inventory_consumptions')) {
                    DB::table('inventory_consumptions')->where('layer_id', $layer->id)->delete();
                }
                if (Schema::hasTable('stock_ledger')) {
                    DB::table('stock_ledger')->where('layer_id', $layer->id)->delete();
                }
                DB::table('inventory_layers')->where('id', $layer->id)->delete();

                $productStores[] = [
                    'product_id' => (int) $layer->product_id,
                    'store_id'   => $layer->store_location_id !== null ? (int) $layer->store_location_id : null,
                ];
            }

            if ($grItem->goodsReceipt?->gr_number) {
                $grNumbers[] = $grItem->goodsReceipt->gr_number;
            }
            if ($grItem->goods_receipt_id) {
                $grIds[] = (int) $grItem->goods_receipt_id;
            }
            $grItem->delete();
        }

        foreach (array_unique($grIds) as $grId) {
            if (GoodsReceiptItem::where('goods_receipt_id', $grId)->doesntExist()) {
                GoodsReceipt::where('id', $grId)->delete();
            }
        }

        return [
            'product_stores' => $productStores,
            'gr_numbers' => array_values(array_unique($grNumbers)),
        ];
    }

    private function logLineCancel(User $user, Purchase $purchase, PurchaseItem $item, string $message): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        ActivityLog::create([
            'actor_type' => 'staff',
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'actor_role' => $user->role,
            'store_location_id' => $purchase->store_location_id,
            'method' => 'DELETE',
            'path' => '/purchases/'.$purchase->id.'/items/'.$item->id,
            'action' => 'purchase.line_cancel',
            'description' => $message,
            'subject_type' => 'purchase_item',
            'subject_id' => (string) $item->id,
            'status_code' => 200,
            'created_at' => now(),
        ]);
    }

    private function reverseRemaining(GoodsReceipt $gr, User $user, string $reason): GoodsReceipt
    {
        $items = $gr->items()->lockForUpdate()->get();
        $anyConsumed = false;
        $productStores = [];

        foreach ($items as $item) {
            $itemReversed = 0.0;
            $layers = $this->layersForItem($item);

            foreach ($layers as $layer) {
                $initial = (float) $layer->qty_initial;
                $remaining = (float) $layer->qty_remaining;
                $alreadyReversed = (float) ($layer->qty_reversed ?? 0);
                $consumed = max(0, $initial - $remaining - $alreadyReversed);
                if ($consumed > self::EPS) {
                    $anyConsumed = true;
                }

                if ($remaining <= self::EPS) {
                    if ($consumed > self::EPS && Schema::hasColumn('inventory_layers', 'consumed_review_flagged')) {
                        DB::table('inventory_layers')->where('id', $layer->id)->update([
                            'consumed_review_flagged' => true,
                            'updated_at' => now(),
                        ]);
                    }
                    continue;
                }

                $unitCost = (float) ($layer->unit_landed_cost ?? $layer->unit_cost ?? 0);

                if (Schema::hasTable('stock_ledger')) {
                    StockLedgerWriter::write([
                        'product_id'        => (int) $layer->product_id,
                        'direction'         => -1,
                        'qty'               => $remaining,
                        'unit_cost'         => $unitCost,
                        'store_location_id' => $layer->store_location_id,
                        'layer_id'          => (int) $layer->id,
                        'user_id'           => $user->id,
                        'ref_type'          => 'GR_REVERSAL',
                        'ref_id'            => $gr->id,
                        'note'              => $reason,
                    ]);
                }

                $update = [
                    'qty_remaining' => 0,
                    'updated_at'    => now(),
                ];
                if (Schema::hasColumn('inventory_layers', 'qty_reversed')) {
                    $update['qty_reversed'] = $alreadyReversed + $remaining;
                }
                if (Schema::hasColumn('inventory_layers', 'status')) {
                    $update['status'] = 'reversed';
                }
                if ($consumed > self::EPS && Schema::hasColumn('inventory_layers', 'consumed_review_flagged')) {
                    $update['consumed_review_flagged'] = true;
                }

                DB::table('inventory_layers')->where('id', $layer->id)->update($update);

                $itemReversed += $remaining;
                $productStores[] = [
                    'product_id' => (int) $layer->product_id,
                    'store_id'   => $layer->store_location_id !== null ? (int) $layer->store_location_id : null,
                ];
            }

            if ($itemReversed > self::EPS) {
                if (Schema::hasColumn('goods_receipt_items', 'qty_reversed')) {
                    $item->qty_reversed = (float) ($item->qty_reversed ?? 0) + $itemReversed;
                    $item->save();
                }

                $pi = PurchaseItem::where('id', $item->purchase_item_id)->lockForUpdate()->first();
                if ($pi) {
                    $pi->qty_received = max(0, (float) $pi->qty_received - $itemReversed);
                    $pi->save();
                }
            }
        }

        $gr->reversed_at = $gr->reversed_at ?? now();
        if ($anyConsumed) {
            $gr->review_flagged_at = $gr->review_flagged_at ?? now();
            $gr->review_flagged_by = $gr->review_flagged_by ?? $user->id;
            $gr->review_reason = $gr->review_reason
                ?: ('Consumed qty remains after GR reversal. '.$reason);
        }
        $gr->save();

        if ($gr->purchase) {
            $this->refreshPurchaseReceiveStatus($gr->purchase);
        }

        foreach ($productStores as $row) {
            InventoryService::syncLegacyProductStock($row['product_id'], $row['store_id']);
        }

        return $gr->fresh(['items', 'purchase']);
    }

    /** @return list<object> */
    private function layersForGr(GoodsReceipt $gr): array
    {
        $itemIds = $gr->items()->pluck('id')->all();
        if ($itemIds === [] || ! Schema::hasTable('inventory_layers')) {
            return [];
        }

        return DB::table('inventory_layers')
            ->where('source_type', 'GR')
            ->whereIn('source_id', $itemIds)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @return list<object> */
    private function layersForItem(GoodsReceiptItem $item): array
    {
        if (! Schema::hasTable('inventory_layers')) {
            return [];
        }

        return DB::table('inventory_layers')
            ->where('source_type', 'GR')
            ->where('source_id', $item->id)
            ->orderBy('id')
            ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())
            ->get()
            ->all();
    }

    public function refreshPurchaseReceiveStatus(Purchase $purchase): void
    {
        $status = strtolower((string) $purchase->status);
        if (in_array($status, ['draft', 'canceled', 'cancelled'], true)) {
            return;
        }

        $items = $purchase->items()->get()->filter(fn (PurchaseItem $item) => ! $item->isCancelled());
        if ($items->isEmpty()) {
            if (! in_array($status, ['draft'], true)) {
                $purchase->status = 'canceled';
                $purchase->save();
            }

            return;
        }

        $anyReceived = false;
        $allFilled = true;
        foreach ($items as $item) {
            $received = (float) $item->qty_received;
            $ordered = (float) $item->qty_order;
            if ($received > self::EPS) {
                $anyReceived = true;
            }
            if ($received + self::EPS < $ordered) {
                $allFilled = false;
            }
        }

        if ($allFilled && $anyReceived) {
            $purchase->status = 'closed';
        } elseif ($anyReceived) {
            $purchase->status = 'partially_received';
        } else {
            $purchase->status = 'approved';
        }
        $purchase->save();
    }

    public function recalculatePurchaseTotals(Purchase $purchase): void
    {
        $subtotal = 0.0;
        $taxTotal = 0.0;
        foreach ($purchase->items as $item) {
            if ($item->isCancelled()) {
                continue;
            }
            $subtotal += ($item->qty_order * $item->unit_price) - $item->discount;
            $taxTotal += $item->tax;
        }
        $purchase->subtotal = $subtotal;
        $purchase->tax_total = $taxTotal;
        $purchase->grand_total = $subtotal + $taxTotal + (float) $purchase->other_cost;
        $purchase->save();
    }
}
