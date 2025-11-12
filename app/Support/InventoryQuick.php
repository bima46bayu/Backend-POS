<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class InventoryQuick
{
    public static function addInboundLayer(array $p): ?int
    {
        $productId = (int)($p['product_id'] ?? 0);
        $qty       = (float)($p['qty'] ?? 0);
        if ($productId <= 0 || $qty <= 0) return null;

        $schema = DB::getSchemaBuilder();

        return DB::transaction(function () use ($schema, $productId, $qty, $p) {
            // === Ambil harga product utk diisi ke unit_price / unit_cost / unit_landed_cost ===
            $prod  = DB::table('products')->where('id', $productId)->select('price')->first();
            $price = (float)($prod->price ?? 0);

            $cols = $schema->getColumnListing('inventory_layers');

            $payload = [
                'product_id'    => $productId,
                'qty_initial'   => $qty,
                'qty_remaining' => $qty,
            ];

            // 🏪 Tambahkan store_location_id bila ada
            if (in_array('store_location_id', $cols, true)) {
                $payload['store_location_id'] = $p['store_location_id'] ?? null;
            }

            // 💲 isi harga ke berbagai kolom biaya bila ada
            if (in_array('unit_price', $cols, true))        $payload['unit_price']        = $price;
            if (in_array('unit_cost', $cols, true))         $payload['unit_cost']         = $price;
            if (in_array('unit_landed_cost', $cols, true))  $payload['unit_landed_cost']  = $price;
            if (in_array('estimated_cost', $cols, true))    $payload['estimated_cost']    = $price;

            if (in_array('note', $cols, true))       $payload['note'] = $p['note'] ?? 'Add product initial stock';
            if (in_array('created_at', $cols, true)) $payload['created_at'] = now();
            if (in_array('updated_at', $cols, true)) $payload['updated_at'] = now();

            // 🧾 source_type / ref_type aman
            $hadSourceType = false;
            if (in_array('source_type', $cols, true)) {
                $payload['source_type'] = $p['source_type'] ?? 'ADD_PRODUCT';
                $hadSourceType = true;
            }
            if (in_array('ref_type', $cols, true) && array_key_exists('ref_type', $p)) {
                $payload['ref_type'] = $p['ref_type'];
            }
            if (in_array('source_id', $cols, true) && array_key_exists('ref_id', $p)) {
                $payload['source_id'] = $p['ref_id'];
            }

            // 🪄 Insert dengan fallback kalau enum tidak menerima ADD_PRODUCT
            $layerId = null;
            try {
                $layerId = DB::table('inventory_layers')->insertGetId($payload);
            } catch (QueryException $e) {
                if ($hadSourceType) unset($payload['source_type']);
                try {
                    $layerId = DB::table('inventory_layers')->insertGetId($payload);
                } catch (QueryException $e2) {
                    DB::table('inventory_layers')->insert($payload);
                    $layerId = null;
                }
            }

            // 🔁 Sync aggregate di products
            if ($schema->hasColumn('products','stock')) {
                DB::table('products')->where('id', $productId)->update([
                    'stock'      => DB::raw('stock + '.$qty),
                    'updated_at' => now(),
                ]);
            } elseif ($schema->hasColumn('products','stock_qty')) {
                DB::table('products')->where('id', $productId)->update([
                    'stock_qty'  => DB::raw('stock_qty + '.$qty),
                    'updated_at' => now(),
                ]);
            }

            return $layerId;
        });
    }
}
