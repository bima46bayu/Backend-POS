<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryController extends Controller
{
    /** Normalisasi arah (dir) sesuai skema baru */
    private function normDir(string $refType = null, $direction = null): int
    {
        $t = strtoupper((string)$refType);
        if (in_array($t, ['SALE','DESTROY','SALE_VOID'], true)) return -1;
        if (in_array($t, ['GR','ADD'], true)) return +1;
        return (int)($direction ?? 0);
    }

    /** Ambil Opening dari IMPORT_INIT layers */
    /**
     * Baca opening dari inventory_layers untuk berbagai varian sumber opening.
     * Menerima: IMPORT_INIT (baru), IMPORT_OPEN (import excel), ADD/ADD_PRODUCT (legacy seed).
     */
    protected function openingFromImportInit(int $productId, ?int $storeId = null): array
    {
        if (!Schema::hasTable('inventory_layers')) {
            return ['qty' => 0.0, 'cost' => 0.0];
        }

        $cols  = Schema::getColumnListing('inventory_layers');
        $has   = fn(string $n) => in_array($n, $cols, true);
        $first = function (array $cands) use ($cols): ?string {
            foreach ($cands as $c) if (in_array($c, $cols, true)) return $c;
            return null;
        };

        $storeCol = $first(['store_location_id','store_id']);
        $srcCol   = $first(['source_type','source','ref_type']);
        $qtyCol   = $first(['qty_initial','initial_qty','opening_qty','qty']);    // prefer explicit opening qty
        $remCol   = $first(['qty_remaining','remaining_qty','remaining_quantity']); // cadangan
        $costCol  = $first(['unit_landed_cost','unit_cost','unit_price','cost']);  // prefer landed/unit_cost
        $estCol   = $has('estimated_cost') ? 'estimated_cost' : null;

        // semua varian yang dianggap "opening"
        $openingSources = ['IMPORT_INIT','IMPORT_OPEN','IMPORT_ADJUST','ADD','ADD_PRODUCT'];

        $q = DB::table('inventory_layers')->where('product_id', $productId);
        if ($storeId !== null && $storeCol) {
            $q->where($storeCol, $storeId);
        }
        if ($srcCol) {
            $q->whereIn($srcCol, $openingSources);
        }

        // Kuantitas opening: pakai qtyCol jika ada; jika tidak ada, fallback ke remaining (umumnya sama saat opening)
        $qtyExpr = $qtyCol ?: $remCol;
        $openingQty = 0.0;
        if ($qtyExpr) {
            $openingQty = (float) (clone $q)->sum($qtyExpr);
        }

        // Biaya opening:
        // - jika ada estimated_cost → pakai SUM(estimated_cost)
        // - else SUM(qtyExpr * costCol)
        $openingCost = 0.0;
        if ($estCol) {
            $openingCost = (float) (clone $q)->sum($estCol);
        } else {
            if ($qtyExpr && $costCol) {
                // build expr aman (nama kolom tidak bisa di-bind parameter)
                $openingCost = (float) (clone $q)
                    ->selectRaw('COALESCE(SUM((' . $qtyExpr . ') * (' . $costCol . ')),0) AS c')
                    ->value('c');
            }
        }

        return ['qty' => $openingQty, 'cost' => $openingCost];
    }


    /**
     * GET /api/inventory/layers
     * Query: product_id?, store_id?, per_page?
     */
    public function layers(Request $r)
    {
        if (!Schema::hasTable('inventory_layers')) {
            return response()->json([
                'items' => [], 'meta' => ['current_page'=>1,'per_page'=>0,'last_page'=>1,'total'=>0], 'links' => []
            ]);
        }

        // siapkan subquery konsumsi hanya jika tabel & kolomnya ada
        $joinAgg = null;
        if (Schema::hasTable('inventory_consumptions')) {
            $hasReversed = Schema::hasColumn('inventory_consumptions', 'reversed_at');

            $select = [
                'layer_id',
                DB::raw('SUM(qty) as qty_total'),
            ];

            if ($hasReversed) {
                $select = [
                    'layer_id',
                    DB::raw('SUM(CASE WHEN reversed_at IS NULL THEN qty ELSE 0 END) as qty_consumed'),
                    DB::raw('SUM(CASE WHEN reversed_at IS NOT NULL THEN qty ELSE 0 END) as qty_reversed'),
                    DB::raw('MAX(GREATEST(UNIX_TIMESTAMP(created_at), IFNULL(UNIX_TIMESTAMP(reversed_at),0))) as last_activity_unix')
                ];
            }

            $joinAgg = DB::table('inventory_consumptions')
                ->select($select)
                ->groupBy('layer_id');
        }

        $q = DB::table('inventory_layers as il');
        if ($joinAgg) $q->leftJoinSub($joinAgg, 'agg', 'agg.layer_id', '=', 'il.id');

        $landedExpr = 'COALESCE(il.unit_landed_cost, il.unit_cost, il.unit_price, 0)';

        $selectRaw = "
            il.id, il.product_id, il.store_location_id,
            ".(Schema::hasColumn('inventory_layers','source_type') ? 'il.source_type,' : 'NULL as source_type,')."
            ".(Schema::hasColumn('inventory_layers','source_id')   ? 'il.source_id,'   : 'NULL as source_id,')."
            ".(Schema::hasColumn('inventory_layers','unit_price')  ? 'il.unit_price,'  : 'NULL as unit_price,')."
            ".(Schema::hasColumn('inventory_layers','unit_tax')    ? 'il.unit_tax,'    : '0 as unit_tax,')."
            ".(Schema::hasColumn('inventory_layers','unit_other_cost') ? 'il.unit_other_cost,' : '0 as unit_other_cost,')."
            {$landedExpr} as unit_landed_cost,
            il.qty_initial, il.qty_remaining, il.created_at
        ";

        if ($joinAgg) {
            if (Schema::hasColumn('inventory_consumptions','reversed_at')) {
                $selectRaw .= ",
                    IFNULL(agg.qty_consumed,0) as qty_consumed,
                    IFNULL(agg.qty_reversed,0) as qty_reversed,
                    FROM_UNIXTIME(agg.last_activity_unix) as last_activity_at
                ";
            } else {
                $selectRaw .= ",
                    IFNULL(agg.qty_total,0) as qty_consumed,
                    0 as qty_reversed,
                    NULL as last_activity_at
                ";
            }
        } else {
            $selectRaw .= ",
                0 as qty_consumed,
                0 as qty_reversed,
                NULL as last_activity_at
            ";
        }

        $q->selectRaw($selectRaw)
          ->when($r->filled('product_id'), fn($qq)=>$qq->where('il.product_id', $r->product_id))
          ->when($r->filled('store_id'),   fn($qq)=>$qq->where('il.store_location_id', $r->store_id))
          ->orderByDesc('il.id');

        $per = max(1, min(200, (int)($r->per_page ?? 50)));
        return response()->json($q->paginate($per));
    }

    /**
     * GET /api/inventory/consumptions
     * Query: product_id?, sale_id?, per_page?
     */
    public function consumptions(Request $r)
    {
        if (!Schema::hasTable('inventory_consumptions')) {
            return response()->json([
                'items' => [], 'meta' => ['current_page'=>1,'per_page'=>0,'last_page'=>1,'total'=>0], 'links' => []
            ]);
        }

        $q = DB::table('inventory_consumptions as ic')
            ->select(
                'ic.id','ic.product_id','ic.store_location_id',
                'ic.sale_id','ic.sale_item_id','ic.layer_id',
                'ic.qty','ic.unit_cost','ic.created_at'
            )
            ->when($r->filled('product_id'), fn($qq) => $qq->where('ic.product_id', $r->product_id))
            ->when($r->filled('sale_id'),    fn($qq) => $qq->where('ic.sale_id', $r->sale_id))
            ->orderByDesc('ic.id');

        $per = max(1, min(200, (int)($r->per_page ?? 50)));
        return response()->json($q->paginate($per));
    }

    /**
     * GET /api/inventory/valuation
     * Query: product_id?, store_id?, per_page?
     */
    public function valuation(Request $r)
    {
        if (!Schema::hasTable('inventory_layers')) {
            return response()->json([
                'items' => [], 'meta' => ['current_page'=>1,'per_page'=>0,'last_page'=>1,'total'=>0], 'links' => []
            ]);
        }

        $landedExpr = 'COALESCE(unit_landed_cost, unit_cost, unit_price, 0)';

        $q = DB::table('inventory_layers')
            ->select(
                'product_id',
                DB::raw('SUM(qty_remaining) as qty_on_hand'),
                DB::raw("SUM(qty_remaining * {$landedExpr}) as inventory_value")
            )
            ->when($r->filled('product_id'), fn($qq) => $qq->where('product_id', $r->product_id))
            ->when($r->filled('store_id'),   fn($qq) => $qq->where('store_location_id', $r->store_id))
            ->groupBy('product_id')
            ->orderBy('product_id');

        $per = max(1, min(200, (int)($r->per_page ?? 50)));
        return response()->json($q->paginate($per));
    }

    /**
     * GET /api/inventory/products
     */
    public function inventoryProducts(Request $r)
    {
        $q = DB::table('products')
            ->select('id','sku','name','price','stock','updated_at')
            ->when($r->filled('search'), function($qq) use ($r) {
                $s = $r->input('search');
                $qq->where(function($w) use ($s) {
                    $w->where('name','like',"%$s%")->orWhere('sku','like',"%$s%");
                });
            })
            ->orderBy('updated_at','desc');

        $p = $q->paginate(min(max((int)$r->input('per_page',20),1),100))->appends($r->query());

        return response()->json([
            'items'=>$p->items(),
            'meta'=>[
                'current_page'=>$p->currentPage(),
                'per_page'=>$p->perPage(),
                'last_page'=>$p->lastPage(),
                'total'=>$p->total()
            ],
            'links'=>['next'=>$p->nextPageUrl(),'prev'=>$p->previousPageUrl()],
        ]);
    }

    /**
     * GET /api/inventory/products/{id}/logs
     * Query: date_from?, date_to?, store_id?, ref_type?, per_page?, page?, hide_cost?
     */
public function productLogs($productId, Request $r)
{
    if (!Schema::hasTable('stock_ledger')) {
        return response()->json([
            'items' => [],
            'meta'  => ['current_page'=>1,'per_page'=>0,'last_page'=>1,'total'=>0],
            'links' => [],
        ]);
    }

    $q = DB::table('stock_ledger')->where('product_id', (int)$productId)
        ->when($r->filled('store_id'),  fn($qq)=>$qq->where('store_location_id',(int)$r->input('store_id')))
        ->when($r->filled('ref_type'),  fn($qq)=>$qq->where('ref_type',$r->input('ref_type')))
        ->when($r->filled('date_from'), fn($qq)=>$qq->where('created_at','>=',$r->input('date_from').' 00:00:00'))
        ->when($r->filled('date_to'),   fn($qq)=>$qq->where('created_at','<=',$r->input('date_to').' 23:59:59'))
        ->orderBy('created_at')->orderBy('id');

    $rows = $q->get([
        'id','product_id','store_location_id','layer_id','user_id',
        'ref_type','ref_id','direction','qty',
        'unit_cost','unit_price','subtotal_cost',
        'note','created_at',
    ]);

    $hideCost = (bool)$r->boolean('hide_cost', false);

    // Running balance dgn arah yang sudah DINORMALISASI
    $bal = 0;
    $rows = $rows->map(function ($row) use (&$bal, $hideCost) {

        $ref = strtoupper((string)$row->ref_type);

        // === NORMALISASI DIRECTION ===
        // - SALE, DESTROY  -> -1 (keluar)
        // - SALE_VOID      -> +1 (masuk kembali)  <<< PERUBAHAN PENTING
        // - GR, ADD        -> +1 (masuk)
        // - lainnya        -> pakai direction asal (fallback 1 jika null)
        $dir = match ($ref) {
            'SALE'      => -1,
            'DESTROY'   => -1,
            'SALE_VOID' => +1,   // force masuk
            'GR', 'ADD' => +1,
            default     => (int)($row->direction ?? 1),
        };

        $qty        = (float)($row->qty ?? 0);
        $unitPrice  = (float)($row->unit_price ?? 0);
        $unitCost   = (float)($row->unit_cost  ?? 0);

        // Fallback subtotal_cost jika belum ada
        $subtotalCost = isset($row->subtotal_cost) && $row->subtotal_cost !== null
            ? (float)$row->subtotal_cost
            : ($qty * $unitCost);

        // Update running balance pakai direction yang sudah dinormalisasi
        $bal += ($dir * $qty);
        $row->running_balance = $bal;

        // Simpan nilai yg sudah dibetulkan
        $row->direction     = $dir;
        $row->subtotal_cost = $subtotalCost;

        // Profitability hanya untuk SALE keluar (bukan SALE_VOID)
        $isSale = ($ref === 'SALE' && $dir === -1);
        $row->line_revenue = $isSale ? ($qty * $unitPrice) : 0.0;
        $row->line_cogs    = $isSale ? ($qty * $unitCost)  : 0.0;
        $row->line_gross   = $row->line_revenue - $row->line_cogs;

        if ($hideCost) {
            unset($row->unit_cost, $row->subtotal_cost, $row->line_cogs);
        }

        return $row;
    });

    // Paginate manual (biar running_balance konsisten)
    $per   = min(max((int)$r->input('per_page', 50), 1), 200);
    $page  = max((int)$r->input('page', 1), 1);
    $total = $rows->count();
    $items = $rows->slice(($page - 1) * $per, $per)->values();

    return response()->json([
        'items' => $items,
        'meta'  => [
            'current_page' => $page,
            'per_page'     => $per,
            'last_page'    => (int)ceil($total / $per),
            'total'        => $total,
        ],
        'links' => [],
    ]);
}


    /**
     * GET /api/inventory/products/{id}/summary
     * Query: date_from?, date_to?, store_id?
     *
     * SKEMA BARU:
     * - Opening: dari IMPORT_INIT layers
     * - In     : hanya GR
     * - Out    : SALE, DESTROY, SALE_VOID
     * - COGS   : hanya SALE
     */
    public function productSummary($productId, Request $r)
    {
        if (!Schema::hasTable('stock_ledger')) {
            return response()->json([
                'product_id'       => (int)$productId,
                'qty_in'           => 0,
                'qty_out'          => 0,
                'gross_revenue'    => 0,
                'cogs'             => 0,
                'gross_profit'     => 0,
                'avg_sale_price'   => null,
                'avg_cost'         => null,
                'ending_stock'     => (int) DB::table('products')->where('id',$productId)->value('stock'),
                'opening_qty'      => 0,
                'opening_cost'     => 0,
                'cost_in'          => 0,
                'cost_out'         => 0,
                'total_cost'       => 0,
                'stock_cost_total' => 0,
                'period'           => ['from'=>$r->input('date_from'),'to'=>$r->input('date_to')],
            ]);
        }

        $storeId = $r->filled('store_id') ? (int)$r->input('store_id') : null;

        // ===== 1) Opening dari IMPORT_INIT layers =====
        $opening = $this->openingFromImportInit((int)$productId, $storeId);
        $openingQty  = (float)$opening['qty'];
        $openingCost = (float)$opening['cost'];

        $base = DB::table('stock_ledger')->where('product_id',$productId)
            ->when($storeId !== null, fn($qq)=>$qq->where('store_location_id',$storeId))
            ->when($r->filled('date_from'), fn($qq)=>$qq->where('created_at','>=',$r->input('date_from').' 00:00:00'))
            ->when($r->filled('date_to'),   fn($qq)=>$qq->where('created_at','<=',$r->input('date_to').' 23:59:59'));

        // 1b) Fallback opening dari LOG ADD paling awal (jika layers = 0 atau cost = 0)
        if ($openingQty <= 0 || $openingCost <= 0.0) {
            $firstAddAt = (clone $base)->where('ref_type','ADD')->min('created_at');
            if ($firstAddAt) {
                $addOpen = (clone $base)
                    ->where('ref_type','ADD')
                    ->where('created_at', $firstAddAt) // grup ADD pertama sebagai opening
                    ->selectRaw('COALESCE(SUM(qty),0) as oqty,
                                COALESCE(SUM(COALESCE(subtotal_cost, qty * unit_cost)),0) as ocost')
                    ->first();
                $openingQty  = max($openingQty,  (float)($addOpen->oqty  ?? 0));
                $openingCost = max($openingCost, (float)($addOpen->ocost ?? 0));
            }
        }

        // ===== 2) IN: hanya GR (+1) =====
        $qtyIn = (int)(clone $base)->where('ref_type','GR')->where('direction',+1)->sum('qty');
        $inCost = (float)(clone $base)->where('ref_type','GR')->where('direction',+1)
            ->selectRaw('COALESCE(SUM(COALESCE(subtotal_cost, qty * unit_cost)),0) as ic')->value('ic');

        // ===== 3) Hapus efek SALE yang di-void =====
        // Ambil sale_id yang di-void dari baris SALE_VOID (asumsi ref_id = sale_id)
        $voidSaleIds = (clone $base)
            ->where('ref_type', 'SALE_VOID')
            ->pluck('ref_id')
            ->filter()
            ->unique()
            ->values();

        // SALE valid (tidak di-void)
        $saleValid = (clone $base)
            ->where('ref_type','SALE')
            ->where('direction',-1)
            ->when($voidSaleIds->isNotEmpty(), fn($qq)=>$qq->whereNotIn('ref_id', $voidSaleIds));

        // DESTROY tetap out
        $destroyBase = (clone $base)
            ->where('ref_type','DESTROY')
            ->where('direction',-1);

        // OUT (qty & cost) hanya dari SALE valid + DESTROY
        $qtyOutSale    = (int) (clone $saleValid)->sum('qty');
        $qtyOutDestroy = (int) (clone $destroyBase)->sum('qty');
        $qtyOut        = $qtyOutSale + $qtyOutDestroy;

        $outCostSale = (float) (clone $saleValid)
            ->selectRaw('COALESCE(SUM(COALESCE(subtotal_cost, qty * unit_cost)),0) as oc')->value('oc');

        $outCostDestroy = (float) (clone $destroyBase)
            ->selectRaw('COALESCE(SUM(COALESCE(subtotal_cost, qty * unit_cost)),0) as oc')->value('oc');

        $outCostAll = $outCostSale + $outCostDestroy;

        // ===== 4) Profitability (Revenue & COGS) hanya dari SALE valid =====
        $revenue = (float) (clone $saleValid)
            ->selectRaw('COALESCE(SUM(qty * unit_price),0) as rev')->value('rev');

        $cogs = (float) (clone $saleValid)
            ->selectRaw('COALESCE(SUM(COALESCE(subtotal_cost, qty * unit_cost)),0) as cogs')->value('cogs');

        // Averages pakai jumlah SALE valid (DESTROY tidak dihitung ke rata-rata)
        $avgSell = $qtyOutSale > 0 ? $revenue / $qtyOutSale : null;
        $avgCost = $qtyOutSale > 0 ? $cogs    / $qtyOutSale : null;

        // ===== 5) Ending & totals =====
        $profit     = $revenue - $cogs;
        $ending     = (int) DB::table('products')->where('id',$productId)->value('stock');
        $costEnding = $openingCost + $inCost - $outCostAll;

        return response()->json([
            'product_id'       => (int)$productId,
            'qty_in'           => $qtyIn,          // GR only
            'qty_out'          => $qtyOut,         // SALE valid + DESTROY
            'gross_revenue'    => $revenue,        // SALE valid saja
            'cogs'             => $cogs,           // SALE valid saja
            'gross_profit'     => $profit,
            'avg_sale_price'   => $avgSell,
            'avg_cost'         => $avgCost,
            'ending_stock'     => $ending,

            'opening_qty'      => $openingQty,
            'opening_cost'     => $openingCost,
            'cost_in'          => $inCost,
            'cost_out'         => $outCostAll,
            'total_cost'       => $costEnding,
            'stock_cost_total' => $costEnding,
            'period'           => ['from'=>$r->input('date_from'),'to'=>$r->input('date_to')],
        ]);
    }

    
}
