<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use App\Models\Product;

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
        // Biaya opening (TOTAL): selalu qty * unit_cost/landed_cost
        $openingCost = 0.0;

        if ($qtyExpr && $costCol) {
            $openingCost = (float) (clone $q)
                ->selectRaw('COALESCE(SUM((' . $qtyExpr . ') * (' . $costCol . ')),0) AS c')
                ->value('c');
        } elseif ($estCol) {
            // fallback terakhir kalau costCol memang tidak ada
            $openingCost = (float) (clone $q)->sum($estCol);
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

    // ==== HELPER: logic summary per produk (tanpa response) ====
    private function buildProductSummaryArray(int $productId, Request $r): array
{
    $product = Product::find($productId);

    if (!$product) {
        return [
            'product_id' => $productId,
            'qty_in' => 0, 'qty_out' => 0,
            'gross_revenue' => 0, 'cogs' => 0, 'gross_profit' => 0,
            'avg_sale_price' => null, 'avg_cost' => null,
            'gross_revenue_total' => 0, 'cogs_total' => 0, 'gross_profit_total' => 0,
            'avg_sale_price_total' => null, 'avg_cost_total' => null,
            'ending_stock' => 0,
            'opening_qty' => 0, 'opening_cost' => 0,
            'cost_in' => 0, 'cost_out' => 0, 'total_cost' => 0, 'stock_cost_total' => 0,
            'period' => ['from'=>$r->input('date_from'),'to'=>$r->input('date_to')],
        ];
    }

    $storeId = $r->filled('store_id') ? (int)$r->input('store_id') : null;

    /* ================= NON STOCK ================= */

    if (!$product->isStockTracked()) {

        $salesBase = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('si.product_id', $productId)
            ->where('s.status', 'completed')
            ->when($storeId !== null, fn($q)=>$q->where('s.store_location_id',$storeId))
            ->when($r->filled('date_from'), fn($q)=>$q->where('s.created_at','>=',$r->input('date_from').' 00:00:00'))
            ->when($r->filled('date_to'), fn($q)=>$q->where('s.created_at','<=',$r->input('date_to').' 23:59:59'));

        $qtyOutSale  = (int)(clone $salesBase)->sum('si.qty');
        $itemRevenue = (float)(clone $salesBase)->sum('si.line_total');

        $saleSubtotal = (float)(clone $salesBase)->selectRaw('SUM(DISTINCT s.subtotal)')->value('SUM(DISTINCT s.subtotal)');
        $saleDiscount = (float)(clone $salesBase)->selectRaw('SUM(DISTINCT s.discount)')->value('SUM(DISTINCT s.discount)');

        $allocatedDiscount = ($saleSubtotal > 0 && $saleDiscount > 0)
            ? ($itemRevenue / $saleSubtotal) * $saleDiscount
            : 0;

        $grossRevenue = $itemRevenue - $allocatedDiscount;
        $grossProfit  = $grossRevenue;

        $avgSalePrice = $qtyOutSale > 0 ? $grossRevenue / $qtyOutSale : null;

        $endingStock = (int)DB::table('products')->where('id',$productId)->value('stock');

        return [
            'product_id' => $productId,
            'gross_revenue' => $grossRevenue,
            'cogs' => 0,
            'gross_profit' => $grossProfit,
            'avg_sale_price' => $avgSalePrice,
            'avg_cost' => null,

            'gross_revenue_total' => $grossRevenue,
            'cogs_total' => 0,
            'gross_profit_total' => $grossProfit,
            'avg_sale_price_total' => $avgSalePrice,
            'avg_cost_total' => null,

            'qty_in' => 0,
            'qty_out' => $qtyOutSale,
            'opening_qty' => 0,
            'opening_cost' => 0,
            'cost_in' => 0,
            'cost_out' => 0,
            'total_cost' => 0,
            'stock_cost_total' => 0,
            'ending_stock' => $endingStock,

            'period' => ['from'=>$r->input('date_from'),'to'=>$r->input('date_to')],
        ];
    }

    /* ================= STOCK PRODUCT ================= */

    $opening = method_exists($this,'openingFromImportInit')
        ? $this->openingFromImportInit($productId,$storeId)
        : ['qty'=>0,'cost'=>0];

    $openingQty  = (float)$opening['qty'];
    $openingCost = (float)$opening['cost'];

    $saleQuery = DB::table('stock_ledger as sl')
        ->join('sales as s', 's.id', '=', 'sl.ref_id')
        ->join('sale_items as si', function($join){
            $join->on('si.sale_id','=','s.id')
                 ->on('si.product_id','=','sl.product_id');
        })
        ->where('sl.product_id',$productId)
        ->where('sl.ref_type','SALE')
        ->where('sl.direction',-1)
        ->where('s.status','completed')
        ->when($storeId !== null, fn($q)=>$q->where('sl.store_location_id',$storeId))
        ->when($r->filled('date_from'), fn($q)=>$q->where('sl.created_at','>=',$r->input('date_from').' 00:00:00'))
        ->when($r->filled('date_to'), fn($q)=>$q->where('sl.created_at','<=',$r->input('date_to').' 23:59:59'));

    $qtyOutSale  = (int)(clone $saleQuery)->sum('sl.qty');
    $itemRevenue = (float)(clone $saleQuery)->sum('si.line_total');

    $saleSubtotal = (float)(clone $saleQuery)->selectRaw('SUM(DISTINCT s.subtotal)')->value('SUM(DISTINCT s.subtotal)');
    $saleDiscount = (float)(clone $saleQuery)->selectRaw('SUM(DISTINCT s.discount)')->value('SUM(DISTINCT s.discount)');

    $allocatedDiscount = ($saleSubtotal > 0 && $saleDiscount > 0)
        ? ($itemRevenue / $saleSubtotal) * $saleDiscount
        : 0;

    $grossRevenue = $itemRevenue - $allocatedDiscount;

    $cogs = (float)(clone $saleQuery)
        ->selectRaw('SUM(COALESCE(sl.subtotal_cost, sl.qty * sl.unit_cost))')
        ->value('SUM(COALESCE(sl.subtotal_cost, sl.qty * sl.unit_cost))');

    $grossProfit = $grossRevenue - $cogs;

    $avgSalePrice = $qtyOutSale > 0 ? $grossRevenue / $qtyOutSale : null;
    $avgCost      = $qtyOutSale > 0 ? $cogs / $qtyOutSale : null;

    $endingStock = (int)DB::table('products')->where('id',$productId)->value('stock');

    $costIn = (float)DB::table('stock_ledger')
        ->where('product_id',$productId)
        ->where('direction',1)
        ->sum(DB::raw('COALESCE(subtotal_cost, qty * unit_cost)'));

    $costOut = (float)DB::table('stock_ledger')
        ->where('product_id',$productId)
        ->where('direction',-1)
        ->sum(DB::raw('COALESCE(subtotal_cost, qty * unit_cost)'));

    $costEnding = $openingCost + $costIn - $costOut;

    return [
        'product_id' => $productId,

        'gross_revenue' => $grossRevenue,
        'cogs' => $cogs,
        'gross_profit' => $grossProfit,
        'avg_sale_price' => $avgSalePrice,
        'avg_cost' => $avgCost,

        'gross_revenue_total' => $grossRevenue,
        'cogs_total' => $cogs,
        'gross_profit_total' => $grossProfit,
        'avg_sale_price_total' => $avgSalePrice,
        'avg_cost_total' => $avgCost,

        'qty_in' => 0,
        'qty_out' => $qtyOutSale,
        'opening_qty' => $openingQty,
        'opening_cost' => $openingCost,
        'cost_in' => $costIn,
        'cost_out' => $costOut,
        'total_cost' => $costEnding,
        'stock_cost_total' => $costEnding,
        'ending_stock' => $endingStock,

        'period' => ['from'=>$r->input('date_from'),'to'=>$r->input('date_to')],
    ];
}

    // ==== BATCH: GET /api/inventory/products/summary ====
    public function productSummaryBatch(Request $r)
    {
        try {
            $v = Validator::make($r->all(), [
                'product_ids' => ['required'], // string "1,2,3" atau array
                'date_from'   => ['nullable', 'date'],
                'date_to'     => ['nullable', 'date'],
                'from'        => ['nullable', 'date'],
                'to'          => ['nullable', 'date'],
                'store_id'    => ['nullable', 'integer'],
                'max'         => ['nullable', 'integer', 'min:1', 'max:1000'],
            ]);
            if ($v->fails()) {
                return response()->json(['message'=>'Invalid params','errors'=>$v->errors()], 422);
            }

            // kompat: from/to -> date_from/date_to
            if ($r->filled('from') && !$r->filled('date_from')) $r->merge(['date_from' => $r->input('from')]);
            if ($r->filled('to')   && !$r->filled('date_to'))   $r->merge(['date_to'   => $r->input('to')]);

            $idsRaw = $r->input('product_ids');
            $ids = is_array($idsRaw)
                ? $idsRaw
                : (is_string($idsRaw) ? preg_split('/[,\s]+/', $idsRaw, -1, PREG_SPLIT_NO_EMPTY) : []);
            $ids = array_values(array_unique(array_map('intval', $ids)));

            if (empty($ids)) {
                return response()->json([
                    'items'  => [],
                    'totals' => ['cogs'=>0, 'gross_profit'=>0],
                    'count'  => 0,
                    'meta'   => [
                        'from' => $r->input('date_from'),
                        'to'   => $r->input('date_to'),
                        'store_id' => $r->input('store_id'),
                    ],
                ]);
            }

            $max = min((int)($r->input('max', 500)), 1000);
            $ids = array_slice($ids, 0, $max);

            $items = [];
            $totalCogs = 0.0;
            $totalGp   = 0.0;

            foreach ($ids as $pid) {
                $row = $this->buildProductSummaryArray((int)$pid, $r);
                $cogs = (float)($row['cogs'] ?? 0);
                $gp   = (float)($row['gross_profit'] ?? 0);
                $items[] = [
                    'product_id'   => (int)$pid,
                    'cogs'         => $cogs,
                    'gross_profit' => $gp,
                ];
                $totalCogs += $cogs;
                $totalGp   += $gp;
            }

            return response()->json([
                'items'  => $items,
                'totals' => ['cogs' => $totalCogs, 'gross_profit' => $totalGp],
                'count'  => count($items),
                'meta'   => [
                    'from' => $r->input('date_from'),
                    'to'   => $r->input('date_to'),
                    'store_id' => $r->input('store_id'),
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'Batch summary error',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function productSummary($productId, Request $r)
    {
        $row = $this->buildProductSummaryArray((int)$productId, $r);
        return response()->json($row);
    }
}
