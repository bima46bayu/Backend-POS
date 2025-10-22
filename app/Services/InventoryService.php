<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryService
{
    /**
     * Dipanggil saat GR: buat satu layer FIFO dengan harga beli (landed sederhana).
     */
    public function addInboundLayer(array $p): void
    {
        $productId = (int)$p['product_id'];
        $qty       = (float)$p['qty'];
        if ($qty <= 0) return;

        $unitBuy   = (float)($p['unit_buy'] ?? 0);
        $unitTax   = (float)($p['unit_tax'] ?? 0);
        $unitOther = (float)($p['unit_other_cost'] ?? 0);
        $landed    = $unitBuy + $unitTax + $unitOther;

        DB::table('inventory_layers')->insert([
            'product_id'        => $productId,
            'store_location_id' => $p['store_location_id'] ?? null,
            'source_type'       => 'GR',
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

        if ($need <= 0) return [];

        // helper: ambil layer tertua yg masih ada qty
        $nextLayer = function (bool $withStore) use ($productId, $storeId) {
            $q = DB::table('inventory_layers')
                ->where('product_id', $productId)
                ->where('qty_remaining', '>', 0)
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate();
            if ($withStore && !is_null($storeId)) {
                $q->where('store_location_id', $storeId);
            }
            return $q->first();
        };

        $taken = [];
        // 1) coba pakai filter store (jika ada)
        while ($need > $eps) {
            $layer = $nextLayer(true);
            if (!$layer) break;

            $take = min($need, (float)$layer->qty_remaining);

            DB::table('inventory_consumptions')->insert([
                'product_id'        => $productId,
                'store_location_id' => $storeId, // simpan sesuai sale
                'sale_id'           => $saleId,
                'sale_item_id'      => $saleItemId,
                'layer_id'          => $layer->id,
                'qty'               => $take,
                'unit_cost'         => $layer->unit_landed_cost,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            DB::table('inventory_layers')->where('id', $layer->id)->update([
                'qty_remaining' => DB::raw('qty_remaining - '.(float)$take),
                'updated_at'    => now(),
            ]);

            $taken[] = [
                'layer_id'        => $layer->id,
                'qty'             => $take,
                'unit_cost'       => (float)$layer->unit_landed_cost,
                'unit_sale_price' => $saleUnit,
            ];

            $need -= $take;
        }

        // 2) fallback tanpa filter store
        while ($need > $eps) {
            $layer = $nextLayer(false);
            if (!$layer) break;

            $take = min($need, (float)$layer->qty_remaining);

            DB::table('inventory_consumptions')->insert([
                'product_id'        => $productId,
                'store_location_id' => $storeId, // tetap simpan store dari sale
                'sale_id'           => $saleId,
                'sale_item_id'      => $saleItemId,
                'layer_id'          => $layer->id,
                'qty'               => $take,
                'unit_cost'         => $layer->unit_landed_cost,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            DB::table('inventory_layers')->where('id', $layer->id)->update([
                'qty_remaining' => DB::raw('qty_remaining - '.(float)$take),
                'updated_at'    => now(),
            ]);

            $taken[] = [
                'layer_id'        => $layer->id,
                'qty'             => $take,
                'unit_cost'       => (float)$layer->unit_landed_cost,
                'unit_sale_price' => $saleUnit,
            ];

            $need -= $take;
        }

        // GUARD: kalau masih butuh, fail biar ketahuan (bukan silently skip)
        if ($need > $eps) {
            throw new RuntimeException("FIFO: layer tidak cukup untuk product_id={$productId}, sisa_need={$need}");
        }

        return $taken;
    }
}
