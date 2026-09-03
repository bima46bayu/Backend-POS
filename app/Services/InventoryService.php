<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use App\Models\Product;
use App\Support\StockLedgerWriter;

class InventoryService
{
    /** Sum qty_remaining from layers (optionally per store). */
    public static function sumQtyRemaining(int $productId, ?int $storeId = null): float
    {
        if (! Schema::hasTable('inventory_layers')) {
            return (float) (Product::find($productId)?->stock ?? 0);
        }

        $q = DB::table('inventory_layers')->where('product_id', $productId);
        if ($storeId !== null) {
            $q->where('store_location_id', $storeId);
        }
        if (Schema::hasColumn('inventory_layers', 'status')) {
            $q->where(function ($w) {
                $w->whereNull('status')->orWhere('status', '!=', 'reversed');
            });
        }

        return (float) $q->sum('qty_remaining');
    }

    /**
     * Mirror one branch's layer qty into legacy products.stock (optional fallback column).
     * Skips when storeId is null — never writes a cross-store total into products.stock.
     */
    public static function syncLegacyProductStock(int $productId, ?int $storeId): void
    {
        if (! Schema::hasColumn('products', 'stock')) {
            return;
        }

        $product = Product::find($productId);
        if (! $product) {
            return;
        }

        if (! $product->isStockTracked()) {
            DB::table('products')->where('id', $productId)->update([
                'stock'      => 0,
                'updated_at' => now(),
            ]);

            return;
        }

        if ($storeId === null) {
            return;
        }

        DB::table('products')->where('id', $productId)->update([
            'stock'      => self::sumQtyRemaining($productId, $storeId),
            'updated_at' => now(),
        ]);
    }

    /** Stock for API display: layers when store scoped, else legacy column (non-stock → 0). */
    public static function displayStock(int $productId, ?int $storeId, ?Product $product = null): float
    {
        $product ??= Product::find($productId);
        if ($product && ! $product->isStockTracked()) {
            return 0.0;
        }

        if ($storeId !== null && Schema::hasTable('inventory_layers')) {
            return self::sumQtyRemaining($productId, $storeId);
        }

        return (float) ($product?->stock ?? 0);
    }

    /**
     * The ONE way to create an inbound FIFO layer (GR, opening stock, import,
     * stock-opname adjustments, returns).
     *
     * Previously three near-copies of this existed (Support\InventoryQuick,
     * Services\StockService, plus inline code in StockReconciliationController).
     * They disagreed on cost basis, on whether the ledger was written, on
     * whether products.stock was synced, and on whether inventory_type was
     * honoured. Everything routes here now so those can't drift again.
     *
     * Cost basis, in priority order:
     *   1. explicit unit_buy (+ unit_tax + unit_other_cost) → landed cost
     *   2. explicit unit_cost
     *   3. products.cost_price
     *   4. 0.0 — NEVER products.price, which is the sell price. Using the sell
     *      price as cost makes COGS equal revenue and zeroes reported margin.
     *
     * Returns the new layer id, or null when nothing was written (product
     * missing, non-stock item, or qty <= 0).
     */
    public function addInboundLayer(array $p): ?int
    {
        $productId = (int) ($p['product_id'] ?? 0);
        if ($productId <= 0) {
            return null;
        }

        $product = Product::find($productId);
        if (! $product) {
            return null;
        }

        // NON-STOCK → never create a layer. Guard lives here so callers can't
        // forget it (they each used to re-implement this check).
        if (! $product->isStockTracked()) {
            return null;
        }

        // Receiving is always expressed in the product's stock unit. A product
        // bought by the pack has its Unit set to "Pack", so 1 received pack is
        // 1 stock unit and pack_size never enters the inbound path — it only
        // tells a recipe how finely a pack can be consumed. Accepting
        // pack_qty/pack_price here would divide the cost a second time.
        $qty = (float) ($p['qty'] ?? 0);

        if ($qty <= 0) {
            return null;
        }

        $unitTax   = (float) ($p['unit_tax'] ?? 0);
        $unitOther = (float) ($p['unit_other_cost'] ?? 0);

        if (array_key_exists('unit_buy', $p)) {
            $unitBuy = (float) $p['unit_buy'];
        } elseif (array_key_exists('unit_cost', $p)) {
            $unitBuy = (float) $p['unit_cost'];
        } else {
            $unitBuy = $product->costBasis();
        }

        $landed     = $unitBuy + $unitTax + $unitOther;
        $sourceType = strtoupper((string) ($p['source_type'] ?? $p['ref_type'] ?? 'GR'));
        $storeId    = $p['store_location_id'] ?? null;
        $sourceId   = $p['source_id'] ?? $p['ref_id'] ?? null;
        $note       = $p['note'] ?? null;

        return DB::transaction(function () use (
            $productId, $storeId, $sourceType, $sourceId, $unitBuy, $unitTax,
            $unitOther, $landed, $qty, $note, $p
        ) {
            $cols = Schema::getColumnListing('inventory_layers');

            $payload = [
                'product_id'    => $productId,
                'qty_initial'   => $qty,
                'qty_remaining' => $qty,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];

            // Schema varies across environments, so only set what exists.
            $optional = [
                'store_location_id' => $storeId,
                'source_type'       => $sourceType,
                'source_id'         => $sourceId,
                'unit_price'        => $unitBuy,
                'unit_tax'          => $unitTax,
                'unit_other_cost'   => $unitOther,
                'unit_landed_cost'  => $landed,
                'unit_cost'         => $landed,
                'estimated_cost'    => $landed * $qty,
                'status'            => 'open',
                'qty_reversed'      => 0,
                'note'              => $note,
            ];
            foreach ($optional as $col => $value) {
                if (in_array($col, $cols, true)) {
                    $payload[$col] = $value;
                }
            }

            $layerId = null;
            try {
                $layerId = DB::table('inventory_layers')->insertGetId($payload);
            } catch (QueryException $e) {
                // Legacy environments may still constrain source_type to a
                // narrow enum. Retrying without it keeps the stock correct, but
                // a NULL source_type is invisible to opening-stock reporting —
                // so make the trade-off loud instead of silent.
                if (! array_key_exists('source_type', $payload)) {
                    throw $e;
                }

                Log::warning('inventory_layers.source_type rejected; inserting without it', [
                    'product_id'  => $productId,
                    'source_type' => $payload['source_type'],
                    'hint'        => 'Run the 2026_08_20_000004 migration to widen this column.',
                ]);

                unset($payload['source_type']);
                $layerId = DB::table('inventory_layers')->insertGetId($payload);
            }

            // Ledger write is part of this operation, not the caller's job.
            // `ledger_ref_type` lets callers keep a reporting ref_type that
            // differs from the layer's source_type (e.g. stock opname records
            // the layer as ADJUSTMENT_IN but the ledger as RECON_ADJUST).
            if (Schema::hasTable('stock_ledger')) {
                StockLedgerWriter::write([
                    'product_id'        => $productId,
                    'direction'         => +1,
                    'qty'               => $qty,
                    'unit_cost'         => $landed,
                    'store_location_id' => $storeId,
                    'layer_id'          => $layerId,
                    'user_id'           => $p['user_id'] ?? auth()->id(),
                    'ref_type'          => $p['ledger_ref_type'] ?? $sourceType,
                    'ref_id'            => $p['ledger_ref_id'] ?? $sourceId,
                    'note'              => $note,
                ]);
            }

            // Keep the legacy mirror column in step for this branch.
            self::syncLegacyProductStock($productId, $storeId !== null ? (int) $storeId : null);

            return $layerId;
        });
    }

    /**
     * Dipanggil per SaleItem: konsumsi FIFO multi-layer.
     * Akan THROW kalau tidak bisa menghabiskan qty yang diminta (supaya ketahuan).
     */
    public function consumeFIFOWithPricing(array $p): array
    {
        $need       = (float)$p['qty'];
        $productId  = (int)$p['product_id'];
        $storeId    = $p['store_location_id'] ?? null;
        $saleId     = $p['sale_id'] ?? null;
        $saleItemId = $p['sale_item_id'] ?? null;
        $saleUnit   = (float)$p['sale_unit_price'];
        $eps        = 1e-9;

        /*
         | Offline sales: the customer already paid before we could verify stock,
         | so consume whatever layers exist and let the caller record the gap
         | instead of blowing up the whole transaction.
         */
        $allowShortfall = (bool) ($p['allow_shortfall'] ?? false);

        // ❗ Tambahan: cek product dan tipe inventory
        $product = Product::find($productId);
        if (!$product) {
            // optional: mau throw atau skip, di sini aku choose skip
            return [];
        }

        // NON-STOCK → jangan konsumsi layer sama sekali
        if (! $product->isStockTracked() || $need <= 0) {
            return [];
        }

        if ($storeId === null) {
            throw new RuntimeException("FIFO: store_location_id wajib untuk product={$product->name}");
        }

        $taken = [];
        while ($need > $eps) {
            $layer = self::fifoEligibleQuery($productId, $storeId)
                ->lockForUpdate()
                ->first();

            if (! $layer) {
                break;
            }

            $take = min($need, (float) $layer->qty_remaining);

            DB::table('inventory_consumptions')->insert([
                'product_id'        => $productId,
                'store_location_id' => $storeId,
                'sale_id'           => $saleId,
                'sale_item_id'      => $saleItemId,
                'layer_id'          => $layer->id,
                'qty'               => $take,
                'unit_cost'         => $layer->unit_landed_cost,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            self::decrementLayerQty($layer, $take);

            $taken[] = [
                'layer_id'        => $layer->id,
                'qty'             => $take,
                'unit_cost'       => (float) $layer->unit_landed_cost,
                'unit_sale_price' => $saleUnit,
            ];

            $need -= $take;
        }

        if ($need > $eps && ! $allowShortfall) {
            throw new RuntimeException(
                "FIFO: Stok cabang tidak cukup untuk product={$product->name}, sisa_need={$need}"
            );
        }

        return $taken;
    }

    /**
     * Open FIFO layers only — reversed layers stay in the ledger but are never
     * drawn from, even if qty_remaining was restored by a later void.
     */
    public static function fifoEligibleQuery(int $productId, int $storeId)
    {
        $q = DB::table('inventory_layers')
            ->where('product_id', $productId)
            ->where('store_location_id', $storeId)
            ->where('qty_remaining', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id');

        if (Schema::hasColumn('inventory_layers', 'status')) {
            $q->where(function ($w) {
                $w->whereNull('status')->orWhere('status', 'open');
            });
        }

        return $q;
    }

    public static function decrementLayerQty(object $layer, float $take): float
    {
        $newRemaining = max(0, (float) $layer->qty_remaining - $take);
        $update = [
            'qty_remaining' => $newRemaining,
            'updated_at'    => now(),
        ];

        if (Schema::hasColumn('inventory_layers', 'status')) {
            $current = (string) ($layer->status ?? 'open');
            if ($current !== 'reversed') {
                $update['status'] = $newRemaining <= 1e-9 ? 'closed' : 'open';
            }
        }

        DB::table('inventory_layers')->where('id', $layer->id)->update($update);

        return $newRemaining;
    }

    public static function restoreLayerQty(object $layer, float $qty, float $cap): float
    {
        $newRemaining = min($cap, (float) ($layer->qty_remaining ?? 0) + $qty);
        $update = [
            'qty_remaining' => $newRemaining,
            'updated_at'    => now(),
        ];

        if (Schema::hasColumn('inventory_layers', 'status')) {
            if ($newRemaining > 1e-9) {
                $update['status'] = 'open';
            } elseif (($layer->status ?? '') !== 'reversed') {
                $update['status'] = 'closed';
            }
        }

        DB::table('inventory_layers')->where('id', $layer->id)->update($update);

        return $newRemaining;
    }
}
