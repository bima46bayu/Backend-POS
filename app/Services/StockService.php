<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\StockLedgerWriter as Ledger;

class StockService
{
    // ===== Inbound: GR =====
    // $p: product_id, qty, unit_cost (harga beli/landed), ref_id? (gr_id), note?, store_location_id?
    public function gr(array $p): ?int
    {
        return $this->addInboundLayer(array_merge(['ref_type' => 'GR', 'note' => $p['note'] ?? 'GR'], $p));
    }

    // ===== Inbound: Add Product (stok awal) =====
    public function addProduct(array $p): ?int
    {
        if (!isset($p['unit_cost'])) {
            $p['unit_cost'] = (float)(DB::table('products')->where('id', $p['product_id'])->value('price') ?? 0);
        }
        $p['ref_type'] = 'ADD_PRODUCT';
        $p['note']     = $p['note'] ?? 'Initial stock';
        return $this->addInboundLayer($p);
    }

    // ===== Inbound: Void (retur jual) → masuk lagi =====
    public function void(array $p): ?int
    {
        $p['ref_type'] = 'VOID';
        $p['note']     = $p['note'] ?? 'Void';
        return $this->addInboundLayer($p);
    }

    // ===== Outbound: Sale (FIFO konsumsi layer) =====
    // $p: items[] = [product_id, qty, unit_price, sale_item_id?], sale_id?, store_location_id?
    public function sale(array $p): void
    {
        DB::transaction(function () use ($p) {
            foreach ($p['items'] as $it) {
                $need      = (int)$it['qty'];
                $pid       = (int)$it['product_id'];
                $sellPrice = (float)($it['unit_price'] ?? 0);

                // Ambil layer FIFO yang masih ada qty
                $layers = DB::table('inventory_layers')
                    ->where('product_id', $pid)
                    ->where('qty_remaining', '>', 0)
                    ->orderBy('created_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get(['id', 'product_id', 'qty_remaining', 'unit_cost', 'unit_landed_cost', 'store_location_id']);

                foreach ($layers as $ly) {
                    if ($need <= 0) break;

                    $take = min($need, (int)$ly->qty_remaining);
                    $cost = (float)($ly->unit_cost ?? $ly->unit_landed_cost ?? 0);

                    // Kurangi layer
                    DB::table('inventory_layers')->where('id', $ly->id)->update([
                        'qty_remaining' => DB::raw('qty_remaining - ' . $take),
                        'updated_at'    => now(),
                    ]);

                    // (opsional) simpan consumption
                    if (Schema::hasTable('inventory_consumptions')) {
                        DB::table('inventory_consumptions')->insert([
                            'product_id'        => (int)$ly->product_id,
                            'store_location_id' => $p['store_location_id'] ?? ($ly->store_location_id ?? null),
                            'sale_id'           => $p['sale_id'] ?? null,
                            'sale_item_id'      => $it['sale_item_id'] ?? null,
                            'layer_id'          => (int)$ly->id,
                            'qty'               => $take,
                            'unit_cost'         => $cost,
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ]);
                    }

                    // Ledger OUT (unit_price dari sale, unit_cost dari layer)
                    Ledger::write([
                        'product_id'        => (int)$ly->product_id,
                        'direction'         => -1,
                        'qty'               => $take,
                        'unit_cost'         => $cost,             // ← penting
                        'unit_price'        => $sellPrice,
                        'layer_id'          => (int)$ly->id,
                        'store_location_id' => $p['store_location_id'] ?? ($ly->store_location_id ?? null),
                        'ref_type'          => 'SALE',
                        'ref_id'            => $it['sale_item_id'] ?? $p['sale_id'] ?? null,
                        'note'              => 'Penjualan',
                    ]);

                    $need -= $take;
                }

                // Kurangi agregat stock product
                DB::table('products')->where('id', $pid)->update([
                    'stock'      => DB::raw('stock - ' . (int)$it['qty']),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    // ===== Outbound: Destroy layer (buang sisa layer) =====
    public function destroyLayer(int $layerId, array $opt = []): void
    {
        DB::transaction(function () use ($layerId, $opt) {
            $ly = DB::table('inventory_layers')->where('id', $layerId)->lockForUpdate()->first([
                'id', 'product_id', 'qty_remaining', 'unit_cost', 'unit_landed_cost', 'store_location_id'
            ]);
            if (!$ly) return;

            $remain = (int)$ly->qty_remaining;
            if ($remain > 0) {
                $cost = (float)($ly->unit_cost ?? $ly->unit_landed_cost ?? 0);

                Ledger::write([
                    'product_id'        => (int)$ly->product_id,
                    'direction'         => -1,
                    'qty'               => $remain,
                    'unit_cost'         => $cost,
                    'layer_id'          => (int)$ly->id,
                    'store_location_id' => $ly->store_location_id ?? null,
                    'ref_type'          => 'DESTROY',
                    'ref_id'            => $opt['ref_id'] ?? null,
                    'note'              => $opt['note'] ?? 'Destroy layer',
                ]);

                DB::table('products')->where('id', $ly->product_id)->update([
                    'stock'      => DB::raw('stock - ' . $remain),
                    'updated_at' => now(),
                ]);
            }

            DB::table('inventory_layers')->where('id', $layerId)->delete();
        });
    }

    // ===== Inti inbound (dipakai GR/ADD/VOID) =====
    private function addInboundLayer(array $p): ?int
    {
        $pid = (int)($p['product_id'] ?? 0);
        $qty = max(0, (int)($p['qty'] ?? 0));
        if ($pid <= 0 || $qty <= 0) return null;

        $cols = Schema::getColumnListing('inventory_layers');

        return DB::transaction(function () use ($pid, $qty, $p, $cols) {
            $price = (float)($p['unit_cost'] ?? DB::table('products')->where('id', $pid)->value('price') ?? 0);

            $payload = [
                'product_id'    => $pid,
                'qty_initial'   => $qty,
                'qty_remaining' => $qty,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];

            if (in_array('store_location_id', $cols, true))  $payload['store_location_id'] = $p['store_location_id'] ?? null;
            if (in_array('unit_price',       $cols, true))   $payload['unit_price']        = $price;
            if (in_array('unit_cost',        $cols, true))   $payload['unit_cost']         = $price;
            if (in_array('unit_landed_cost', $cols, true))   $payload['unit_landed_cost']  = $price;
            if (in_array('note',             $cols, true))   $payload['note']              = $p['note'] ?? null;

            $hadSourceType = false;
            if (in_array('source_type', $cols, true)) {
                $payload['source_type'] = $p['ref_type'] ?? 'GR';
                $hadSourceType = true;
            }
            if (in_array('source_id', $cols, true) && isset($p['ref_id'])) {
                $payload['source_id'] = $p['ref_id'];
            }

            try {
                $layerId = DB::table('inventory_layers')->insertGetId($payload);
            } catch (\Illuminate\Database\QueryException $e) {
                // fallback jika kolom source_type tidak ada di beberapa env
                if ($hadSourceType) unset($payload['source_type']);
                DB::table('inventory_layers')->insert($payload);
                $layerId = null;
            }

            // Update stok agregat
            DB::table('products')->where('id', $pid)->update([
                'stock'      => DB::raw('stock + ' . $qty),
                'updated_at' => now(),
            ]);

            // Ledger IN (unit_cost WAJIB diisi agar subtotal_cost ikut tersimpan)
            Ledger::write([
                'product_id'        => $pid,
                'direction'         => +1,
                'qty'               => $qty,
                'unit_cost'         => $price, // penting
                'unit_price'        => $price, // tidak dipakai revenue tapi aman disimpan
                'store_location_id' => $p['store_location_id'] ?? null,
                'layer_id'          => $layerId,
                'ref_type'          => $p['ref_type'] ?? 'GR',
                'ref_id'            => $p['ref_id'] ?? null,
                'note'              => $p['note'] ?? null,
            ]);

            return $layerId;
        });
    }
}
