<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockReconciliationService
{
    /** Resolve kolom yg mungkin berbeda di inventory_layers */
    protected function layersCols(): array {
        $cols  = Schema::getColumnListing('inventory_layers');
        $first = function (array $cands) use ($cols) {
            foreach ($cands as $c) if (in_array($c, $cols, true)) return $c;
            return null;
        };
        return [
            'store'    => $first(['store_location_id','store_id']),
            'qtyRem'   => $first(['qty_remaining','remaining_qty','remaining_quantity']),
            'qty'      => $first(['qty','quantity','initial_qty','opening_qty','qty_initial','qty_opening']),
            'unitCost' => $first(['unit_landed_cost','unit_cost','unit_price','cost']),
            'src'      => $first(['source_type','source','ref_type']),
            'note'     => in_array('note', $cols, true) ? 'note' : null,
            'created'  => in_array('created_at', $cols, true) ? 'created_at' : null,
            'updated'  => in_array('updated_at', $cols, true) ? 'updated_at' : null,
        ];
    }

    /** Resolve kolom di stock_ledger */
    protected function ledgerCols(): array {
        $cols  = Schema::getColumnListing('stock_ledger');
        $has   = fn($n) => in_array($n, $cols, true);
        $first = function (array $cands) use ($cols) {
            foreach ($cands as $c) if (in_array($c, $cols, true)) return $c;
            return null;
        };
        return [
            'store'  => $first(['store_location_id','store_id']),
            'ref'    => $first(['ref_type','source_type']),
            'refId'  => $first(['ref_id','source_id']),
            'qtyIn'  => $has('qty_in') ? 'qty_in' : null,
            'qtyOut' => $has('qty_out') ? 'qty_out' : null,
            'qty'    => $has('qty') ? 'qty' : null,
            'dir'    => $has('direction') ? 'direction' : null,
            'uCost'  => $has('unit_cost') ? 'unit_cost' : null,
            'uPrice' => $has('unit_price') ? 'unit_price' : null,
            'subTot' => $has('subtotal_cost') ? 'subtotal_cost' : null,
            'note'   => $has('note') ? 'note' : null,
            'created'=> $has('created_at') ? 'created_at' : null,
            'updated'=> $has('updated_at') ? 'updated_at' : null,
        ];
    }

    /** Ambil stok sistem per product di store: sum qty_remaining (fallback: products.stock) */
    public function systemStocks(int $storeId): array {
        if (!Schema::hasTable('inventory_layers')) {
            return DB::table('products')
                ->when(Schema::hasColumn('products','store_location_id'), fn($q)=>$q->where('store_location_id',$storeId))
                ->pluck('stock','id')->map(fn($v)=>(float)$v)->toArray();
        }
        $C = $this->layersCols();
        $q = DB::table('inventory_layers')->select('product_id');
        if ($C['store'])  $q->where($C['store'], $storeId);
        $remCol = $C['qtyRem'] ?? $C['qty'];
        if (!$remCol) { // fallback hard
            return DB::table('products')->pluck('stock','id')->map(fn($v)=>(float)$v)->toArray();
        }
        $rows = $q->selectRaw("COALESCE(SUM($remCol),0) as remain, product_id")
                  ->groupBy('product_id')->get();
        $out = [];
        foreach ($rows as $r) $out[(int)$r->product_id] = (float)$r->remain;
        return $out;
    }

    /** Hitung avg cost (weighted) per product di periode; fallback 0 */
    public function avgCosts(int $storeId, ?string $from, ?string $to): array {
        if (!Schema::hasTable('stock_ledger')) return [];
        $C = $this->ledgerCols();

        $base = DB::table('stock_ledger');
        if ($C['store']) $base->where($C['store'], $storeId);
        if ($from) $base->where('created_at', '>=', $from.' 00:00:00');
        if ($to)   $base->where('created_at', '<=', $to.' 23:59:59');

        // Weighted avg dari inbound (GR + IMPORT_* + ADD) → jumlahkan cost / qty
        $in = (clone $base)->whereIn($C['ref'] ?? 'ref_type', ['GR','IMPORT_OPEN','IMPORT_INIT','ADD','ADD_PRODUCT','ADJUST_IN']);

        // qty
        if ($C['qtyIn']) {
            $qQty = (clone $in)->selectRaw('product_id, COALESCE(SUM('.$C['qtyIn'].'),0) as q')->groupBy('product_id')->pluck('q','product_id');
        } elseif ($C['qty'] && $C['dir']) {
            $qQty = (clone $in)->where($C['dir'], +1)->selectRaw('product_id, COALESCE(SUM('.$C['qty'].'),0) as q')->groupBy('product_id')->pluck('q','product_id');
        } else {
            $qQty = collect();
        }

        // cost
        if ($C['subTot']) {
            $qCost = (clone $in)->selectRaw('product_id, COALESCE(SUM('.$C['subTot'].'),0) as c')->groupBy('product_id')->pluck('c','product_id');
        } elseif ($C['qty']) {
            $qCost = (clone $in)->selectRaw('product_id, COALESCE(SUM('.$C['qty'].' * COALESCE('.($C['uCost']??'0').',0)),0) as c')->groupBy('product_id')->pluck('c','product_id');
        } else {
            $qCost = collect();
        }

        $avg = [];
        foreach ($qQty as $pid => $qty) {
            $qty = (float)$qty;
            $cost= (float)($qCost[$pid] ?? 0);
            $avg[(int)$pid] = $qty > 0 ? round($cost / $qty, 2) : 0.0;
        }
        return $avg;
    }

    /** Generate nomor referensi sederhana */
    public function nextRef(): string {
        $n = (int) DB::table('stock_reconciliations')->max('id') + 1;
        return 'OPN-'.date('Y-m').'-'.str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    /** Terapkan satu item: IN → tambah layer; OUT → konsumsi FIFO */
    public function applyItem(array $item, int $storeId, int $reconciliationId, int $userId = null): void {
        $pid  = (int)$item['product_id'];
        $diff = (float)$item['diff_stock'];
        $avg  = (float)$item['avg_cost'];
        $now  = now();

        // === Ledger/Layers insert ===
        if ($diff > 0) {
            // ADJUST_IN → tambah layer
            // Gunakan helper InventoryQuick::addInboundLayer jika sudah ada di project kamu:
            if (class_exists('\\App\\Support\\InventoryQuick')) {
                \App\Support\InventoryQuick::addInboundLayer([
                    'product_id'        => $pid,
                    'qty'               => $diff,
                    'unit_cost'         => $avg,
                    'note'              => 'Stock opname adjustment',
                    'store_location_id' => $storeId,
                    'source_type'       => 'ADJUST_IN',
                    'with_ledger'       => true,
                    'ref_id'            => $reconciliationId,
                ]);
            } else {
                // Fallback: tulis langsung ke inventory_layers & stock_ledger (minimal)
                if (Schema::hasTable('inventory_layers')) {
                    $L = $this->layersCols();
                    $data = [
                        'product_id' => $pid,
                        $L['store']  => $storeId,
                    ];
                    $qtyCol = $L['qtyRem'] ?? $L['qty'] ?? null;
                    if ($qtyCol) $data[$qtyCol] = $diff;
                    if ($L['unitCost']) $data[$L['unitCost']] = $avg;
                    if ($L['src']) $data[$L['src']] = 'ADJUST_IN';
                    if ($L['note']) $data[$L['note']] = 'Stock opname adjustment';
                    if ($L['created']) $data[$L['created']] = $now;
                    if ($L['updated']) $data[$L['updated']] = $now;
                    DB::table('inventory_layers')->insert($data);
                }
                if (Schema::hasTable('stock_ledger')) {
                    $C = $this->ledgerCols();
                    $data = ['product_id'=>$pid];
                    if ($C['store']) $data[$C['store']] = $storeId;
                    if ($C['ref'])   $data[$C['ref']]   = 'ADJUST_IN';
                    if ($C['refId']) $data[$C['refId']] = $reconciliationId;
                    if ($C['qtyIn']) $data[$C['qtyIn']] = $diff;
                    elseif ($C['qty'] && $C['dir']) { $data[$C['qty']] = $diff; $data[$C['dir']] = +1; }
                    elseif ($C['qty']) { $data[$C['qty']] = $diff; }
                    if ($C['uCost'])  $data[$C['uCost']] = $avg;
                    if ($C['subTot']) $data[$C['subTot']] = $avg * $diff;
                    if ($C['note'])   $data[$C['note']]   = 'Stock opname adjustment';
                    if ($C['created'])$data[$C['created']] = $now;
                    if ($C['updated'])$data[$C['updated']] = $now;
                    DB::table('stock_ledger')->insert($data);
                }
            }
        } elseif ($diff < 0) {
            // ADJUST_OUT → konsumsi FIFO
            $qtyOut = abs($diff);
            if (class_exists('\\App\\Support\\InventoryService')) {
                // pastikan service mu menyediakan consume FIFO
                \App\Support\InventoryService::consume([
                    'product_id'        => $pid,
                    'qty'               => $qtyOut,
                    'store_location_id' => $storeId,
                    'ref_type'          => 'ADJUST_OUT',
                    'ref_id'            => $reconciliationId,
                    'note'              => 'Stock opname adjustment',
                    'user_id'           => $userId,
                ]);
            } else {
                // Fallback ledger-only (tanpa FIFO detail)
                if (Schema::hasTable('stock_ledger')) {
                    $C = $this->ledgerCols();
                    $data = ['product_id'=>$pid];
                    if ($C['store']) $data[$C['store']] = $storeId;
                    if ($C['ref'])   $data[$C['ref']]   = 'ADJUST_OUT';
                    if ($C['refId']) $data[$C['refId']] = $reconciliationId;
                    if ($C['qtyOut']) $data[$C['qtyOut']] = $qtyOut;
                    elseif ($C['qty'] && $C['dir']) { $data[$C['qty']] = $qtyOut; $data[$C['dir']] = -1; }
                    elseif ($C['qty']) { $data[$C['qty']] = $qtyOut; }
                    if ($C['uCost'])  $data[$C['uCost']] = $avg; // estimasi
                    if ($C['subTot']) $data[$C['subTot']] = $avg * $qtyOut;
                    if ($C['note'])   $data[$C['note']]   = 'Stock opname adjustment';
                    if ($C['created'])$data[$C['created']] = $now;
                    if ($C['updated'])$data[$C['updated']] = $now;
                    DB::table('stock_ledger')->insert($data);
                }
                // kurangi qty_remaining secara kasar (tanpa FIFO detail) – opsional:
                if (Schema::hasTable('inventory_layers')) {
                    $L = $this->layersCols();
                    $remCol = $L['qtyRem'] ?? null;
                    if ($remCol) {
                        // konsumsi dari layer tertua sederhana
                        $layers = DB::table('inventory_layers')
                            ->where('product_id',$pid)
                            ->when($L['store'], fn($q)=>$q->where($L['store'],$storeId))
                            ->where($remCol,'>',0)
                            ->orderBy('id')->lockForUpdate()->get();
                        $need = $qtyOut;
                        foreach ($layers as $ly) {
                            if ($need <= 0) break;
                            $rem = (float)$ly->{$remCol};
                            $take= min($rem, $need);
                            DB::table('inventory_layers')->where('id',$ly->id)->update([
                                $remCol => DB::raw("$remCol - ".$take),
                                $L['updated'] ?? 'updated_at' => now(),
                            ]);
                            $need -= $take;
                        }
                    }
                }
            }
        }
    }

    /** Re-sync kolom products.stock dari layers */
    public function resyncProductsStock(array $productIds, int $storeId): void {
        if (!Schema::hasTable('inventory_layers')) return;
        $L = $this->layersCols();
        $remCol = $L['qtyRem'] ?? $L['qty'];
        if (!$remCol) return;

        foreach (array_unique($productIds) as $pid) {
            $sum = (float) DB::table('inventory_layers')
                ->where('product_id', $pid)
                ->when($L['store'], fn($q)=>$q->where($L['store'],$storeId))
                ->sum($remCol);
            if (Schema::hasColumn('products','stock')) {
                DB::table('products')->where('id',$pid)->update([
                    'stock' => $sum,
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
