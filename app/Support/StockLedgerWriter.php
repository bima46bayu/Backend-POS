<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockLedgerWriter
{
    /**
     * Tulis satu baris ke tabel stock_ledger.
     * WAJIB: product_id, direction (+1/-1), qty, unit_cost, ref_type
     * OPSIONAL: unit_price, ref_id, store_location_id, layer_id, note, created_at, updated_at
     */
    public static function write(array $p): void
    {
        $productId = (int)($p['product_id'] ?? 0);
        $qty       = (float)($p['qty'] ?? 0);
        $direction = (int)($p['direction'] ?? 0);
        $refType   = $p['ref_type'] ?? null;

        if ($productId <= 0 || $qty <= 0 || !in_array($direction, [-1, +1], true) || !$refType) {
            return; // invalid payload → abaikan
        }

        $unitCost  = (float)($p['unit_cost'] ?? 0);
        $unitPrice = (float)($p['unit_price'] ?? 0);

        $payload = [
            'product_id' => $productId,
            'direction'  => $direction,
            'qty'        => $qty,
            'unit_cost'  => $unitCost,
            'unit_price' => $unitPrice,
            'ref_type'   => $refType,
            'created_at' => $p['created_at'] ?? now(),
            'updated_at' => $p['updated_at'] ?? now(),
        ];

        // Fleksibel terhadap skema
        $cols = Schema::getColumnListing('stock_ledger');

        // subtotal_cost = qty * unit_cost (jika kolomnya ada)
        if (in_array('subtotal_cost', $cols, true)) {
            $payload['subtotal_cost'] = $qty * $unitCost;
        }

        if (in_array('ref_id', $cols, true) && array_key_exists('ref_id', $p)) {
            $payload['ref_id'] = $p['ref_id'];
        }
        if (in_array('store_location_id', $cols, true) && array_key_exists('store_location_id', $p)) {
            $payload['store_location_id'] = $p['store_location_id'];
        }
        if (in_array('layer_id', $cols, true) && array_key_exists('layer_id', $p)) {
            $payload['layer_id'] = $p['layer_id'];
        }
        if (in_array('note', $cols, true) && array_key_exists('note', $p)) {
            $payload['note'] = $p['note'];
        }

        DB::table('stock_ledger')->insert($payload);
    }
}
