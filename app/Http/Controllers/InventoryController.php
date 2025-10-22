<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryController extends Controller
{
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

            // pecah consumed/reversed kalau kolomnya ada
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

        if ($joinAgg) {
            $q->leftJoinSub($joinAgg, 'agg', 'agg.layer_id', '=', 'il.id');
        }

        // fallback landed -> cost -> price
        $landedExpr = 'COALESCE(il.unit_landed_cost, il.unit_cost, il.unit_price, 0)';

        $selectRaw = "
            il.id, il.product_id, il.store_location_id,
            " . (Schema::hasColumn('inventory_layers','source_type') ? 'il.source_type,' : 'NULL as source_type,') . "
            " . (Schema::hasColumn('inventory_layers','source_id')   ? 'il.source_id,'   : 'NULL as source_id,')   . "
            " . (Schema::hasColumn('inventory_layers','unit_price')  ? 'il.unit_price,'  : 'NULL as unit_price,')  . "
            " . (Schema::hasColumn('inventory_layers','unit_tax')    ? 'il.unit_tax,'    : '0 as unit_tax,')        . "
            " . (Schema::hasColumn('inventory_layers','unit_other_cost') ? 'il.unit_other_cost,' : '0 as unit_other_cost,') . "
            {$landedExpr} as unit_landed_cost,
            il.qty_initial, il.qty_remaining, il.created_at
        ";

        // tambahkan kolom agregat dari subquery jika ada
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

        // landed -> cost -> price
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
            'unit_cost','unit_price','subtotal_cost',   // <-- penting
            'note','created_at',
        ]);

        $hideCost = (bool)$r->boolean('hide_cost', false);

        // Running balance dari awal (pagination manual supaya akurat)
        $bal = 0;
        $rows = $rows->map(function ($row) use (&$bal, $hideCost) {
            $bal += ((int)$row->direction) * ((int)$row->qty);
            $row->running_balance = $bal;

            $qty       = (float)($row->qty ?? 0);
            $unitPrice = (float)($row->unit_price ?? 0);
            $unitCost  = (float)($row->unit_cost  ?? 0);

            // Fallback jika baris lama belum punya subtotal_cost
            $row->subtotal_cost = isset($row->subtotal_cost) && $row->subtotal_cost !== null
                ? (float)$row->subtotal_cost
                : ($qty * $unitCost);

            // Hitungan untuk SALE (keluar)
            $isSale = ($row->ref_type === 'SALE' && (int)$row->direction === -1);
            $row->line_revenue = $isSale ? ($qty * $unitPrice) : 0.0;
            $row->line_cogs    = $isSale ? ($qty * $unitCost)  : 0.0;
            $row->line_gross   = $row->line_revenue - $row->line_cogs;

            if ($hideCost) {
                // Sembunyikan angka biaya kalau diminta
                unset($row->unit_cost, $row->subtotal_cost, $row->line_cogs);
                // Jika ingin tanpa residu biaya sama sekali, bisa juga:
                // unset($row->line_gross);
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
     */
public function productSummary($productId, Request $r)
{
    if (!Schema::hasTable('stock_ledger')) {
        return response()->json([
            'product_id'     => (int)$productId,
            'qty_in'         => 0,
            'qty_out'        => 0,
            'gross_revenue'  => 0,
            'cogs'           => 0,
            'gross_profit'   => 0,
            'avg_sale_price' => null,
            'avg_cost'       => null,
            'ending_stock'   => (int) DB::table('products')->where('id',$productId)->value('stock'),
            // tambahan biar FE bisa pakai langsung
            'total_cost'       => 0,
            'stock_cost_total' => 0,
            'period'         => ['from'=>$r->input('date_from'),'to'=>$r->input('date_to')],
        ]);
    }

    $base = DB::table('stock_ledger')->where('product_id',$productId)
        ->when($r->filled('store_id'),  fn($qq)=>$qq->where('store_location_id',(int)$r->input('store_id')))
        ->when($r->filled('date_from'), fn($qq)=>$qq->where('created_at','>=',$r->input('date_from').' 00:00:00'))
        ->when($r->filled('date_to'),   fn($qq)=>$qq->where('created_at','<=',$r->input('date_to').' 23:59:59'));

    $qtyIn  = (int)(clone $base)->where('direction',+1)->sum('qty');
    $qtyOut = (int)(clone $base)->where('direction',-1)->sum('qty');

    $revenue = (float)(clone $base)
        ->where('direction',-1)->where('ref_type','SALE')
        ->selectRaw('COALESCE(SUM(qty * unit_price),0) as rev')->value('rev');

    // gunakan subtotal_cost kalau ada; fallback ke qty*unit_cost
    $cogs = (float)(clone $base)
        ->where('direction',-1)
        ->selectRaw('COALESCE(SUM(COALESCE(subtotal_cost, qty * unit_cost)),0) as cogs')->value('cogs');

    // TOTAL COST (sum semua log produk—IN/OUT/DESTROY/ADD, dll)
    $totalCost = (float)(clone $base)
        ->selectRaw('COALESCE(SUM(COALESCE(subtotal_cost, qty * unit_cost)),0) as tot')->value('tot');

    $profit  = $revenue - $cogs;
    $ending  = (int) DB::table('products')->where('id',$productId)->value('stock');
    $avgSell = $qtyOut>0 ? $revenue / $qtyOut : null;
    $avgCost = $qtyOut>0 ? $cogs    / $qtyOut : null;

    return response()->json([
        'product_id'       => (int)$productId,
        'qty_in'           => $qtyIn,
        'qty_out'          => $qtyOut,
        'gross_revenue'    => $revenue,
        'cogs'             => $cogs,
        'gross_profit'     => $profit,
        'avg_sale_price'   => $avgSell,
        'avg_cost'         => $avgCost,
        'ending_stock'     => $ending,
        // ← tambahan supaya FE bisa tampil cepat tanpa fetch per-produk
        'total_cost'       => $totalCost,
        'stock_cost_total' => $totalCost, // alias untuk dipakai di list
        'period'           => ['from'=>$r->input('date_from'),'to'=>$r->input('date_to')],
    ]);
}
}
