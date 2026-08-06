<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use App\Models\Product;

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
     * Dipanggil saat GR: buat satu layer FIFO dengan harga beli (landed sederhana).
     */
    public function addInboundLayer(array $p): void
    {
        $productId = (int) $p['product_id'];

        // ➕ Tambahan: cek product & inventory_type
        $product = Product::find($productId);
        if (!$product) {
            // optional: bisa throw / silent skip. Untuk sekarang kita skip saja.
            return;
        }

        // NON-STOCK → jangan buat layer sama sekali
        if (! $product->isStockTracked()) {
            return;
        }

        $qty = (float) $p['qty'];
        if ($qty <= 0) return;

        $unitBuy   = (float)($p['unit_buy'] ?? 0);
        $unitTax   = (float)($p['unit_tax'] ?? 0);
        $unitOther = (float)($p['unit_other_cost'] ?? 0);
        $landed    = $unitBuy + $unitTax + $unitOther;

        $sourceType = strtoupper((string) ($p['source_type'] ?? 'GR'));

        DB::table('inventory_layers')->insert([
            'product_id'        => $productId,
            'store_location_id' => $p['store_location_id'] ?? null,
            'source_type'       => $sourceType,
            'source_id'         => $p['source_id'] ?? null,
            'unit_price'        => $unitBuy,
            'unit_tax'          => $unitTax,
            'unit_other_cost'   => $unitOther,
            'unit_landed_cost'  => $landed,
            'unit_cost'         => $landed, // alias
            'qty_initial'       => $qty,
            'qty_remaining'     => $qty,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
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
            $layer = DB::table('inventory_layers')
                ->where('product_id', $productId)
                ->where('store_location_id', $storeId)
                ->where('qty_remaining', '>', 0)
                ->orderBy('created_at')
                ->orderBy('id')
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

            DB::table('inventory_layers')->where('id', $layer->id)->update([
                'qty_remaining' => DB::raw('qty_remaining - '.(float) $take),
                'updated_at'    => now(),
            ]);

            $taken[] = [
                'layer_id'        => $layer->id,
                'qty'             => $take,
                'unit_cost'       => (float) $layer->unit_landed_cost,
                'unit_sale_price' => $saleUnit,
            ];

            $need -= $take;
        }

        if ($need > $eps) {
            throw new RuntimeException(
                "FIFO: Stok cabang tidak cukup untuk product={$product->name}, sisa_need={$need}"
            );
        }

        return $taken;
    }
}
