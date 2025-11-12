<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

// Excel (opsional)
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockReconciliationController extends Controller
{
    /* ============================================================
     | Helpers
     * ============================================================ */

    /** Deteksi nama kolom qty pada inventory_layers (qty/remaining_qty/quantity/stock/...) */
    private function detectLayerQtyColumn(): ?string
    {
        if (!Schema::hasTable('inventory_layers')) return null;
        $candidates = ['qty', 'remaining_qty', 'qty_remaining', 'quantity', 'available_qty', 'stock'];
        foreach ($candidates as $col) {
            if (Schema::hasColumn('inventory_layers', $col)) return $col;
        }
        return null;
    }

    /** Weighted avg berdasarkan qty_remaining; cost = COALESCE(unit_landed_cost,unit_cost,unit_price) */
    private function getSystemAggregatePerProduct(int $storeId)
    {
        if (Schema::hasTable('inventory_layers')) {
            $hasQtyRemain = Schema::hasColumn('inventory_layers', 'qty_remaining') || Schema::hasColumn('inventory_layers', 'qty');
            $hasAnyCost   = Schema::hasColumn('inventory_layers','unit_landed_cost')
                          || Schema::hasColumn('inventory_layers','unit_cost')
                          || Schema::hasColumn('inventory_layers','unit_price');

            if ($hasQtyRemain && $hasAnyCost) {
                $qtyCol = $this->detectLayerQtyColumn() ?? 'qty_remaining';
                return DB::table('inventory_layers')
                    ->select(
                        'product_id',
                        DB::raw("COALESCE(SUM({$qtyCol}),0) AS system_qty"),
                        DB::raw("
                            CASE WHEN COALESCE(SUM({$qtyCol}),0)=0 THEN 0
                                 ELSE COALESCE(SUM({$qtyCol} * COALESCE(unit_landed_cost, unit_cost, unit_price)),0)
                                      / NULLIF(SUM({$qtyCol}),0)
                            END AS avg_cost
                        ")
                    )
                    ->where('store_location_id', $storeId)
                    ->where($qtyCol, '>', 0)
                    ->groupBy('product_id')
                    ->get()
                    ->keyBy('product_id');
            }
        }

        // Fallback ke ledger (skema qty_in/qty_out)
        $inSub  = DB::table('stock_ledger')
            ->select('product_id',
                DB::raw('COALESCE(SUM(qty_in),0) AS in_qty'),
                DB::raw('COALESCE(SUM(qty_in * unit_cost),0) AS in_cost'))
            ->where('store_location_id', $storeId)
            ->groupBy('product_id');

        $outSub = DB::table('stock_ledger')
            ->select('product_id', DB::raw('COALESCE(SUM(qty_out),0) AS out_qty'))
            ->where('store_location_id', $storeId)
            ->groupBy('product_id');

        return DB::query()
            ->fromSub($inSub, 'i')
            ->leftJoinSub($outSub, 'o', 'o.product_id', '=', 'i.product_id')
            ->selectRaw('i.product_id,
                        (COALESCE(i.in_qty,0) - COALESCE(o.out_qty,0)) AS system_qty,
                        CASE WHEN COALESCE(i.in_qty,0)=0 THEN 0
                             ELSE COALESCE(i.in_cost/NULLIF(i.in_qty,0),0) END AS avg_cost')
            ->get()
            ->keyBy('product_id');
    }

    /** Generate reference code unik per hari per store */
    private function makeRefCode(int $storeId, ?string $dateFrom): string
    {
        $ymd = $dateFrom ? str_replace('-', '', $dateFrom) : now()->format('Ymd');
        $today = now()->toDateString();
        $seq = DB::table('stock_reconciliations')
            ->where('store_location_id', $storeId)
            ->whereDate('created_at', $today)
            ->count() + 1;

        return sprintf('RECON-%d-%s-%03d', $storeId, $ymd, $seq);
    }

    private function reseedInternal(int $reconId): array
    {
        $head = DB::table('stock_reconciliations')->where('id', $reconId)->first();
        if (!$head) return ['updated' => 0];

        $storeId = (int) $head->store_location_id;
        $now     = now();

        if (!Schema::hasTable('inventory_layers')) {
            $updated = DB::table('stock_reconciliation_items')
                ->where('stock_reconciliation_id', $reconId)
                ->update(['system_qty' => 0, 'avg_cost' => 0, 'updated_at' => $now]);
            return ['updated' => $updated];
        }

        $qtyCol = $this->detectLayerQtyColumn() ?? 'qty_remaining';

        DB::beginTransaction();
        try {
            // 1) Weighted from layers
            DB::statement("
                UPDATE stock_reconciliation_items i
                JOIN (
                    SELECT product_id,
                        COALESCE(SUM({$qtyCol}),0) AS system_qty,
                        CASE WHEN COALESCE(SUM({$qtyCol}),0)=0 THEN 0
                             ELSE COALESCE(SUM({$qtyCol} * COALESCE(unit_landed_cost, unit_cost, unit_price)),0)
                                  / NULLIF(SUM({$qtyCol}),0)
                        END AS avg_cost
                    FROM inventory_layers
                    WHERE store_location_id = ? AND {$qtyCol} > 0
                    GROUP BY product_id
                ) a ON a.product_id = i.product_id
                SET i.system_qty = a.system_qty,
                    i.avg_cost   = a.avg_cost,
                    i.updated_at = ?
                WHERE i.stock_reconciliation_id = ?
            ", [$storeId, $now, $reconId]);

            // 2) Yang tidak punya layer → set 0
            DB::statement("
                UPDATE stock_reconciliation_items i
                LEFT JOIN (
                    SELECT product_id
                    FROM inventory_layers
                    WHERE store_location_id = ? AND {$qtyCol} > 0
                    GROUP BY product_id
                ) a ON a.product_id = i.product_id
                SET i.system_qty = COALESCE(i.system_qty, 0),
                    i.avg_cost   = COALESCE(i.avg_cost, 0),
                    i.updated_at = ?
                WHERE i.stock_reconciliation_id = ? AND a.product_id IS NULL
            ", [$storeId, $now, $reconId]);

            DB::commit();

            $count = DB::table('stock_reconciliation_items')
                ->where('stock_reconciliation_id', $reconId)
                ->count();

            return ['updated' => $count];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /* ============================================================
     | List
     * ============================================================ */
    public function index(Request $r)
    {
        $q = DB::table('stock_reconciliations as sr')
            ->leftJoin('store_locations as sl', 'sl.id', '=', 'sr.store_location_id')
            ->leftJoin('users as u', 'u.id', '=', 'sr.created_by')
            ->select(
                'sr.id','sr.name','sr.status','sr.store_location_id',
                'sr.created_at','sr.applied_at',
                DB::raw('COALESCE(sr.reference_code, "") as reference_code'),
                'sr.date_from','sr.date_to',
                DB::raw('COALESCE(sl.name, "") as store_name'),
                DB::raw('COALESCE(u.name, "") as user_name')
            )
            ->when($r->filled('store_id'), fn($qq)=>$qq->where('sr.store_location_id',(int)$r->input('store_id')))
            ->when($r->filled('status'),   fn($qq)=>$qq->where('sr.status',$r->input('status')))
            ->orderByDesc('sr.id');

        $paginate = $r->boolean('paginate', true);
        if ($paginate) {
            $per = max(1, min(200, (int)$r->input('per_page', 10)));
            return response()->json($q->paginate($per));
        }
        return response()->json(['items'=>$q->get()]);
    }

    /* ============================================================
     | Create + seed items (tanpa Excel)
     * ============================================================ */
    public function store(Request $r)
    {
        $data = $r->validate([
            'name'              => 'nullable|string|max:150',
            'store_location_id' => 'required|integer',
            'date_from'         => 'nullable|string',
            'date_to'           => 'nullable|string',
            'note'              => 'nullable|string',
        ]);

        $now      = Carbon::now();
        $storeId  = (int)$data['store_location_id'];
        $dateFrom = $r->input('date_from') ?: $now->toDateString();
        $dateTo   = $r->input('date_to')   ?: $now->toDateString();
        $name     = $data['name'] ?? ("Rekon Store#{$storeId} " . $now->format('Y-m-d'));

        return DB::transaction(function() use ($storeId, $dateFrom, $dateTo, $name, $now, $r) {
            $insert = [
                'name'              => $name,
                'store_location_id' => $storeId,
                'status'            => 'DRAFT',
                'created_at'        => $now,
                'updated_at'        => $now,
                'created_by'        => auth()->id(),
            ];
            if (Schema::hasColumn('stock_reconciliations','date_from')) $insert['date_from'] = $dateFrom;
            if (Schema::hasColumn('stock_reconciliations','date_to'))   $insert['date_to']   = $dateTo;
            if (Schema::hasColumn('stock_reconciliations','reference_code')) $insert['reference_code'] = $this->makeRefCode($storeId, $dateFrom);
            if (Schema::hasColumn('stock_reconciliations','note') && $r->filled('note')) $insert['note'] = $r->input('note');

            $id = DB::table('stock_reconciliations')->insertGetId($insert);

            // Agregat stok & avg cost dari sistem
            $agg = $this->getSystemAggregatePerProduct($storeId);

            // Ambil daftar produk
            $hasName      = Schema::hasColumn('products', 'name');
            $hasDeletedAt = Schema::hasColumn('products', 'deleted_at');

            $skuExpr = Schema::hasColumn('products','sku') ? 'sku'
                : (Schema::hasColumn('products','code') ? 'code as sku' : "'' as sku");

            $nameExpr = $hasName ? 'name'
                : (Schema::hasColumn('products','product_name') ? 'product_name as name' : "CONCAT('Product #', id) as name");

            $qProducts = DB::table('products')->selectRaw("id as product_id, {$skuExpr}, {$nameExpr}");

            if (Schema::hasColumn('products','is_active')) $qProducts->where('is_active', 1);
            elseif (Schema::hasColumn('products','active')) $qProducts->where('active', 1);
            elseif (Schema::hasColumn('products','status')) $qProducts->whereIn('status', ['ACTIVE','Active','active', 1, '1']);

            if ($hasDeletedAt) $qProducts->whereNull('deleted_at');
            if ($hasName) $qProducts->orderBy('name'); else $qProducts->orderBy('id');

            $products = $qProducts->get();

            // Seed items
            $batch = [];
            foreach ($products as $p) {
                $sys = (int)($agg[$p->product_id]->system_qty ?? 0);
                $avg = (float)($agg[$p->product_id]->avg_cost   ?? 0);

                $batch[] = [
                    'stock_reconciliation_id' => $id,
                    'product_id'              => $p->product_id,
                    'sku'                     => $p->sku,
                    'product_name'            => $p->name,
                    'system_qty'              => $sys,
                    'avg_cost'                => $avg,
                    'physical_qty'            => null,
                    'created_at'              => $now,
                    'updated_at'              => $now,
                ];
            }
            if (!empty($batch)) {
                foreach (array_chunk($batch, 1000) as $chunk) {
                    DB::table('stock_reconciliation_items')->insert($chunk);
                }
            }

            // langsung reseed (ambil dari layers live)
            $this->reseedInternal($id);

            return response()->json([
                'id'              => $id,
                'reference_code'  => DB::table('stock_reconciliations')->where('id',$id)->value('reference_code'),
                'date_from'       => $dateFrom,
                'date_to'         => $dateTo,
            ], 201);
        });
    }

    /* ============================================================
     | Show detail
     * ============================================================ */
    public function show($id)
    {
        $head = DB::table('stock_reconciliations')->where('id',$id)->first();
        if (!$head) return response()->json(['message'=>'Not found'],404);

        $items = DB::table('stock_reconciliation_items')
            ->where('stock_reconciliation_id',$id)
            ->select(
                'id','product_id','sku','product_name',
                'system_qty','avg_cost','physical_qty',
                DB::raw('COALESCE(physical_qty,0) - COALESCE(system_qty,0) AS diff')
            )
            ->orderBy('product_name')
            ->get();

        $canProcess = $items->contains(fn($it)=> $it->physical_qty !== null && (int)$it->physical_qty !== (int)$it->system_qty);

        return response()->json([
            'header'      => $head,
            'items'       => $items,
            'can_process' => $canProcess,
        ]);
    }

    /* ============================================================
     | Inline manual bulk update
     * ============================================================ */
    public function bulkUpdateItems($id, Request $r)
    {
        $payload = $r->validate([
            'items'                 => 'required|array|min:1',
            'items.*.id'            => 'required|integer',
            'items.*.physical_qty'  => 'nullable|numeric',
        ]);

        $now = Carbon::now();

        DB::beginTransaction();
        try {
            foreach ($payload['items'] as $row) {
                DB::table('stock_reconciliation_items')
                    ->where('stock_reconciliation_id', $id)
                    ->where('id', $row['id'])
                    ->update([
                        'physical_qty' => $row['physical_qty'],
                        'updated_at'   => $now,
                    ]);
            }
            DB::commit();
            return response()->json(['message'=>'Updated']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message'=>$e->getMessage()], 500);
        }
    }

    /* ============================================================
     | Template (opsional)
     * ============================================================ */
    public function template($id)
    {
        try {
            $head = DB::table('stock_reconciliations')->where('id',$id)->first();
            if (!$head) return response()->json(['message'=>'Not found'],404);

            $storeId   = (int) $head->store_location_id;
            $storeName = DB::table('store_locations')->where('id',$storeId)->value('name');

            $items = DB::table('stock_reconciliation_items')
                ->where('stock_reconciliation_id',$id)
                ->select('product_id','sku','product_name','physical_qty')
                ->orderBy('product_name')
                ->get();

            $qtyCol = $this->detectLayerQtyColumn() ?? 'qty_remaining';
            $agg = DB::table('inventory_layers')
                ->select(
                    'product_id',
                    DB::raw("COALESCE(SUM({$qtyCol}),0) AS system_qty"),
                    DB::raw("
                        CASE WHEN COALESCE(SUM({$qtyCol}),0)=0 THEN 0
                            ELSE COALESCE(SUM({$qtyCol} * COALESCE(unit_landed_cost, unit_cost, unit_price)),0)
                                / NULLIF(SUM({$qtyCol}),0)
                        END AS avg_cost
                    ")
                )
                ->where('store_location_id', $storeId)
                ->where($qtyCol, '>', 0)
                ->groupBy('product_id')
                ->get()
                ->keyBy('product_id');

            // build spreadsheet
            $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sh = $ss->getActiveSheet();
            $sh->setTitle('Reconciliation');

            $meta = [
                ['Reference Code', $head->reference_code ?? '-'],
                ['Store',          $storeName ?? ('#'.$storeId)],
                ['Period',         ($head->date_from ?? '-') . ' s/d ' . ($head->date_to ?? '-')],
                ['Generated At',   now()->format('Y-m-d H:i:s')],
            ];
            $sh->fromArray($meta, null, 'A1');
            $sh->getStyle('A1:A4')->getFont()->setBold(true);

            $startRow = 6;
            $headers  = ['sku','product_name','system_qty','avg_cost','physical_qty','note'];
            $sh->fromArray([$headers], null, "A{$startRow}");

            $rows = [];
            foreach ($items as $it) {
                $live = $agg[$it->product_id] ?? null;
                $rows[] = [
                    $it->sku,
                    $it->product_name,
                    (int)   ($live->system_qty ?? 0),
                    (float) ($live->avg_cost   ?? 0),
                    $it->physical_qty,
                    '',
                ];
            }
            if ($rows) $sh->fromArray($rows, null, 'A'.($startRow+1));

            foreach (range('A','F') as $col) $sh->getColumnDimension($col)->setAutoSize(true);

            $fileName = 'reconciliation_'.($head->reference_code ?: $id).'.xlsx';
            return response()->streamDownload(function () use ($ss) {
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
                $writer->save('php://output');
            }, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Export failed: '.$e->getMessage()], 500);
        }
    }


    /** Kembalikan source_type yang aman untuk inventory_layers. */
    // tetap pakai nama yang sama
    private function safeLayerSourceType(string $preferred = 'ADJUSTMENT_IN'): ?string
    {
        if (!Schema::hasTable('inventory_layers') || !Schema::hasColumn('inventory_layers','source_type')) return null;

        try {
            $row = DB::selectOne("
                SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH AS len
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'inventory_layers'
                AND COLUMN_NAME = 'source_type'
            ");
        } catch (\Throwable $e) { return null; }

        if (!$row) return null;

        $dataType = strtoupper((string)($row->DATA_TYPE ?? ''));
        $ban = ['ADD','ADD_PRODUCT','OPENING'];

        if ($dataType === 'ENUM') {
            preg_match_all("/'([^']*)'/", (string)$row->COLUMN_TYPE, $m);
            $choices = $m[1] ?? [];

            // urutan preferensi
            foreach (['ADJUSTMENT_IN','ADJUST_IN','ADJUST','RECON'] as $want) {
                if (in_array($want, $choices, true)) return $want;
            }
            foreach ($choices as $c) {
                if (!in_array(strtoupper($c), $ban, true)) return $c;
            }
            return null;
        }

        if (in_array($dataType, ['CHAR','VARCHAR'], true)) {
            $max = (int)($row->len ?? 0);
            if ($max <= 0 || strlen($preferred) <= $max) return $preferred;
            if ($max >= strlen('RECON')) return 'RECON';
            return null;
        }
        return null;
    }

    /* ============================================================
     | Apply → tulis ke stock_ledger (+ layers jika ada kolom qty)
     * ============================================================ */
    public function apply($id)
    {
        $recon = DB::table('stock_reconciliations')->where('id', $id)->first();
        if (!$recon) return response()->json(['message' => 'Reconciliation not found'], 404);
        if (!empty($recon->applied_at)) return response()->json(['message' => 'Already applied'], 422);

        $storeId = (int) $recon->store_location_id;
        $now     = now();
        $refType = 'RECON_ADJUST';

        // kolom qty di inventory_layers
        $qtyCol = Schema::hasTable('inventory_layers') ? ($this->detectLayerQtyColumn() ?? 'qty_remaining') : null;

        // ambil item yang diisi
        $items = DB::table('stock_reconciliation_items')
            ->where('stock_reconciliation_id', $id)
            ->whereNotNull('physical_qty')
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => 'No filled items'], 422);
        }

        // flags untuk kolom-kolom opsional
        $led_hasLayerFK = Schema::hasColumn('stock_ledger','layer_id');
        $led_hasUPrice  = Schema::hasColumn('stock_ledger','unit_price');
        $led_hasSubCost = Schema::hasColumn('stock_ledger','subtotal_cost');
        $led_hasUserId  = Schema::hasColumn('stock_ledger','user_id');

        $cons_hasStore  = Schema::hasColumn('inventory_consumptions','store_location_id');
        $cons_refType   = Schema::hasColumn('inventory_consumptions','ref_type');
        $cons_refId     = Schema::hasColumn('inventory_consumptions','ref_id');
        $cons_layerCol  = Schema::hasColumn('inventory_consumptions','layer_id')
                            ? 'layer_id'
                            : (Schema::hasColumn('inventory_consumptions','inventory_layer_id') ? 'inventory_layer_id' : null);

        DB::beginTransaction();
        try {
            foreach ($items as $it) {
                $sys  = (int)($it->system_qty ?? 0);
                $real = (int)$it->physical_qty;
                $diff = $real - $sys;
                if ($diff === 0) continue;

                $productId = (int)$it->product_id;

                // ==== hitung weighted average cost dari seluruh layer aktif (store & product) ====
                $avgCost = 0.0;
                if ($qtyCol && Schema::hasTable('inventory_layers')) {
                    $avgRow = DB::table('inventory_layers')
                        ->selectRaw("
                            COALESCE(SUM({$qtyCol} * COALESCE(unit_landed_cost, unit_cost, unit_price)),0) AS total_cost,
                            COALESCE(SUM({$qtyCol}),0) AS total_qty
                        ")
                        ->where('product_id', $productId)
                        ->when($storeId, fn($q) => $q->where('store_location_id', $storeId))
                        ->where($qtyCol, '>', 0)
                        ->first();

                    if ($avgRow && (float)$avgRow->total_qty > 0) {
                        $avgCost = (float)$avgRow->total_cost / (float)$avgRow->total_qty;
                    }
                }
                if ($avgCost <= 0) {
                    // fallback dari item.avg_cost → lalu dari last ledger.unit_cost
                    $avgCost = (float)($it->avg_cost ?? 0);
                    if ($avgCost <= 0) {
                        $last = DB::table('stock_ledger')
                            ->where('product_id', $productId)
                            ->when($storeId, fn($q) => $q->where('store_location_id', $storeId))
                            ->orderByDesc('id')
                            ->value('unit_cost');
                        $avgCost = (float)($last ?? 0);
                    }
                }

                // base payload ledger
                $ledgerBase = [
                    'product_id'        => $productId,
                    'store_location_id' => $storeId ?: null,
                    'ref_type'          => $refType,
                    'ref_id'            => $id,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                    'note'              => 'Manual reconciliation',
                ];
                if ($led_hasUserId) $ledgerBase['user_id'] = auth()->id();

                // ================= INBOUND (stok bertambah) =================
                if ($diff > 0) {
                    $newLayerId = null;

                    if (Schema::hasTable('inventory_layers')) {
                        $layerPayload = [
                            'product_id'        => $productId,
                            'store_location_id' => $storeId ?: null,
                            'created_at'        => $now,
                            'updated_at'        => $now,
                        ];
                        // set qty
                        if (Schema::hasColumn('inventory_layers','qty_initial'))  $layerPayload['qty_initial']   = $diff;
                        if (Schema::hasColumn('inventory_layers','qty_remaining')) $layerPayload['qty_remaining'] = $diff;
                        if ($qtyCol && !array_key_exists($qtyCol, $layerPayload)) $layerPayload[$qtyCol] = $diff;

                        // set cost
                        if (Schema::hasColumn('inventory_layers','unit_landed_cost')) $layerPayload['unit_landed_cost'] = $avgCost;
                        if (Schema::hasColumn('inventory_layers','unit_cost'))        $layerPayload['unit_cost']        = $avgCost;
                        if (Schema::hasColumn('inventory_layers','estimated_cost'))   $layerPayload['estimated_cost']   = $avgCost * $diff;

                        // ref/source/note
                        if (Schema::hasColumn('inventory_layers','ref_type'))   $layerPayload['ref_type']   = $refType;
                        if (Schema::hasColumn('inventory_layers','ref_id'))     $layerPayload['ref_id']     = $id;
                        if (Schema::hasColumn('inventory_layers','source_type'))$layerPayload['source_type'] = $this->safeLayerSourceType('ADJUSTMENT_IN');
                        if (Schema::hasColumn('inventory_layers','source_id'))  $layerPayload['source_id']  = $id;
                        if (Schema::hasColumn('inventory_layers','note'))       $layerPayload['note']       = 'Reconciliation IN';

                        $newLayerId = DB::table('inventory_layers')->insertGetId($layerPayload);
                    }

                    // ledger IN (qty positif, direction +1, cost=avg)
                    $payload = $ledgerBase + [
                        'qty'        => $diff,
                        'direction'  => +1,
                        'unit_cost'  => $avgCost,
                    ];
                    if ($led_hasLayerFK) $payload['layer_id'] = $newLayerId;
                    if ($led_hasUPrice)  $payload['unit_price'] = null;               // bukan sales
                    if ($led_hasSubCost) $payload['subtotal_cost'] = $diff * $avgCost;

                    DB::table('stock_ledger')->insert($payload);
                }

                // ================= OUTBOUND (stok berkurang) =================
                else {
                    $need = -$diff;

                    if ($qtyCol && Schema::hasTable('inventory_layers')) {
                        // ambil layer FIFO (lock)
                        $layers = DB::table('inventory_layers')
                            ->where('product_id', $productId)
                            ->when($storeId, fn($q) => $q->where('store_location_id', $storeId))
                            ->where($qtyCol, '>', 0)
                            ->orderBy('id', 'asc')
                            ->lockForUpdate()
                            ->get();

                        foreach ($layers as $ly) {
                            if ($need <= 0) break;
                            $avail = (int)($ly->{$qtyCol} ?? 0);
                            if ($avail <= 0) continue;

                            $take = min($need, $avail);

                            // inventory_consumptions: unit_cost = avgCost (supaya identik dengan ledger)
                            if (Schema::hasTable('inventory_consumptions')) {
                                $consPayload = [
                                    'product_id' => $productId,
                                    'qty'        => $take,
                                    'unit_cost'  => $avgCost,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ];
                                if ($cons_hasStore) $consPayload['store_location_id'] = $storeId ?: null;
                                if ($cons_layerCol) $consPayload[$cons_layerCol]      = $ly->id;
                                if ($cons_refType)  $consPayload['ref_type']          = $refType;
                                if ($cons_refId)    $consPayload['ref_id']            = $id;

                                DB::table('inventory_consumptions')->insert($consPayload);
                            }

                            // ledger OUT: unit_cost = unit_price = avgCost
                            $payload = $ledgerBase + [
                                'qty'        => $take,
                                'direction'  => -1,
                                'unit_cost'  => $avgCost,
                            ];
                            if ($led_hasLayerFK) $payload['layer_id'] = $ly->id;
                            if ($led_hasUPrice)  $payload['unit_price'] = $avgCost;   // <— match consumption
                            if ($led_hasSubCost) $payload['subtotal_cost'] = $take * $avgCost;

                            DB::table('stock_ledger')->insert($payload);

                            // kurangi layer
                            DB::table('inventory_layers')
                                ->where('id', $ly->id)
                                ->update([
                                    $qtyCol      => $avail - $take,
                                    'updated_at' => $now,
                                ]);

                            $need -= $take;
                        }

                        if ($need > 0) {
                            throw new \RuntimeException("Insufficient FIFO layers for product_id={$productId}");
                        }
                    } else {
                        // fallback: tidak ada layers → catat summary saja
                        $take = $need;
                        $payload = $ledgerBase + [
                            'qty'        => $take,
                            'direction'  => -1,
                            'unit_cost'  => $avgCost,
                        ];
                        if ($led_hasUPrice)  $payload['unit_price'] = $avgCost;
                        if ($led_hasSubCost) $payload['subtotal_cost'] = $take * $avgCost;

                        DB::table('stock_ledger')->insert($payload);
                    }
                }

                // sync stok product (global; jika mau per-store, sesuaikan)
                if (Schema::hasTable('inventory_layers') && Schema::hasColumn('products','stock')) {
                    $sumRemain = DB::table('inventory_layers')
                        ->where('product_id', $productId)
                        ->sum($qtyCol ?: 'qty_remaining');
                    DB::table('products')->where('id', $productId)->update([
                        'stock'      => (float)$sumRemain,
                        'updated_at' => $now,
                    ]);
                }
            }

            // update header recon
            DB::table('stock_reconciliations')->where('id', $id)->update([
                'status'     => 'APPLIED',
                'applied_at' => $now,
                'applied_by' => auth()->id(),
                'updated_at' => $now,
            ]);

            DB::commit();
            return response()->json(['message' => 'Applied', 'id' => $id]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /* ============================================================
     | Destroy (hapus DRAFT)
     * ============================================================ */
    public function destroy($id)
    {
        $recon = DB::table('stock_reconciliations')->where('id', $id)->first();
        if (!$recon) return response()->json(['message' => 'Not found'], 404);

        $status = strtoupper((string)($recon->status ?? 'DRAFT'));
        if ($status !== 'DRAFT' || !empty($recon->applied_at)) {
            return response()->json(['message' => 'Only DRAFT reconciliation can be deleted'], 422);
        }

        $refType = 'RECON_ADJUST';

        $hasLedger = false;
        if (Schema::hasTable('stock_ledger')) {
            if (Schema::hasColumn('stock_ledger','ref_type') && Schema::hasColumn('stock_ledger','ref_id')) {
                $hasLedger = DB::table('stock_ledger')->where('ref_type', $refType)->where('ref_id', $id)->exists();
            }
        }

        $hasLayers = false;
        if (Schema::hasTable('inventory_layers')) {
            if (Schema::hasColumn('inventory_layers','ref_type') && Schema::hasColumn('inventory_layers','ref_id')) {
                $hasLayers = DB::table('inventory_layers')->where('ref_type', $refType)->where('ref_id', $id)->exists();
            }
        }

        $hasCons = false;
        if (Schema::hasTable('inventory_consumptions')) {
            if (Schema::hasColumn('inventory_consumptions','ref_type') && Schema::hasColumn('inventory_consumptions','ref_id')) {
                $hasCons = DB::table('inventory_consumptions')->where('ref_type', $refType)->where('ref_id', $id)->exists();
            }
        }

        if ($hasLedger || $hasLayers || $hasCons) {
            return response()->json([
                'message' => 'Cannot delete: related ledger/layers/consumptions exist (not a clean DRAFT).'
            ], 422);
        }

        DB::beginTransaction();
        try {
            DB::table('stock_reconciliation_items')->where('stock_reconciliation_id', $id)->delete();
            DB::table('stock_reconciliations')->where('id', $id)->delete();
            DB::commit();
            return response()->json(['message' => 'Deleted', 'id' => (int)$id]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message'=>$e->getMessage()], 500);
        }
    }
}
