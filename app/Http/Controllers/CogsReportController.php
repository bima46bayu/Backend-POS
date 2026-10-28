<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductRecipe;
use App\Services\RecipeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CogsReportController extends Controller
{
    /**
     * GET /api/reports/cogs-per-sku
     *
     * One row per SKU at the selected store.
     *
     * unit_cogs is the standard cost of one unit: the active recipe priced at
     * each ingredient's cost_price, or the SKU's own cost_price when it is
     * sold from stock directly. cogs is the actual FIFO cost of completed
     * sales in the date range, attributed to the SKU that was sold (ingredient
     * consumption stays on the finished sale line).
     */
    public function perSku(Request $request, RecipeService $recipes)
    {
        $storeId = $this->resolveStoreIdFromRequest($request);

        if ($storeId === null) {
            return response()->json(['message' => 'Store wajib dipilih.'], 422);
        }

        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date'],
            'search'    => ['nullable', 'string', 'max:100'],
        ]);

        $from = $data['date_from'] ?? null;
        $to = $data['date_to'] ?? null;

        if ($from && $to && $from > $to) {
            return response()->json([
                'message' => 'date_from must be on or before date_to.',
            ], 422);
        }

        $search = trim((string) ($data['search'] ?? ''));

        $products = Product::query()
            ->with('unit:id,name')
            ->where(function ($w) use ($storeId) {
                $w->whereNull('store_location_id')
                    ->orWhere('store_location_id', $storeId);
            })
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('sku', 'like', $like)
                        ->orWhere('name', 'like', $like);
                });
            })
            ->orderBy('sku')
            ->orderBy('name')
            ->get();

        $salesByProduct = $this->salesByProduct($storeId, $from, $to);
        $cogsByProduct = $this->cogsByProduct($storeId, $from, $to);

        $knownIds = $products->pluck('id')->map(fn ($id) => (int) $id)->all();
        $soldIds = $salesByProduct->keys()->map(fn ($id) => (int) $id)->all();
        $missingIds = array_values(array_diff($soldIds, $knownIds));

        if ($missingIds !== [] && $search === '') {
            $extra = Product::withTrashed()
                ->with('unit:id,name')
                ->whereIn('id', $missingIds)
                ->get();
            $products = $products->concat($extra);
        }

        $recipeByProduct = ProductRecipe::query()
            ->with(['items.ingredient.unit', 'items.unit'])
            ->where('store_location_id', $storeId)
            ->where('is_active', true)
            ->whereIn('product_id', $products->pluck('id')->all() ?: [0])
            ->get()
            ->keyBy('product_id');

        $items = [];
        $totals = [
            'sku_count'          => 0,
            'qty_sold'           => 0.0,
            'revenue'            => 0.0,
            'cogs'               => 0.0,
            'gross_profit'       => 0.0,
            'missing_cost_count' => 0,
        ];

        foreach ($products as $product) {
            $row = $this->buildRow(
                $product,
                $recipeByProduct->get($product->id),
                $salesByProduct->get($product->id),
                $cogsByProduct->get($product->id),
                $recipes
            );

            $items[] = $row;
            $totals['sku_count']++;
            $totals['qty_sold'] += $row['qty_sold'];
            $totals['revenue'] += $row['revenue'];
            $totals['cogs'] += $row['cogs'];
            $totals['gross_profit'] += $row['gross_profit'];
            if ($row['missing_cost']) {
                $totals['missing_cost_count']++;
            }
        }

        usort($items, function ($a, $b) {
            return [$a['sku'] ?? '', $a['name'] ?? ''] <=> [$b['sku'] ?? '', $b['name'] ?? ''];
        });

        return response()->json([
            'items'  => $items,
            'totals' => [
                'sku_count'          => $totals['sku_count'],
                'qty_sold'           => round($totals['qty_sold'], 4),
                'revenue'            => round($totals['revenue'], 2),
                'cogs'               => round($totals['cogs'], 2),
                'gross_profit'       => round($totals['gross_profit'], 2),
                'missing_cost_count' => $totals['missing_cost_count'],
            ],
            'meta' => [
                'store_id'  => $storeId,
                'date_from' => $from,
                'date_to'   => $to,
                'search'    => $search !== '' ? $search : null,
            ],
        ]);
    }

    private function buildRow(
        Product $product,
        ?ProductRecipe $recipe,
        ?object $sales,
        ?object $cogsRow,
        RecipeService $recipes
    ): array {
        $sellPrice = round((float) ($product->price ?? 0), 2);
        $ingredients = [];
        $unitCogs = null;
        $costSource = 'none';
        $missing = false;

        if ($recipe && $recipe->items->isNotEmpty()) {
            $standard = $recipes->standardUnitCogs($recipe);
            $unitCogs = $standard['unit_cogs'];
            $missing = $standard['missing_cost'];
            $ingredients = $standard['ingredients'];
            $costSource = 'recipe';
        } elseif ($product->isStockTracked()) {
            $unitCogs = round($product->costBasis(), 2);
            $missing = $product->cost_price === null;
            $costSource = 'cost_price';
        }

        $margin = $unitCogs === null ? null : round($sellPrice - $unitCogs, 2);
        $marginPct = ($margin !== null && $sellPrice > 0)
            ? round(($margin / $sellPrice) * 100, 2)
            : null;

        $qtySold = round((float) ($sales->qty_sold ?? 0), 4);
        $revenue = round((float) ($sales->revenue ?? 0), 2);
        $cogs = round((float) ($cogsRow->cogs ?? 0), 2);

        return [
            'product_id'   => (int) $product->id,
            'sku'          => $product->sku,
            'name'         => $product->name,
            'unit_name'    => $product->unit?->name,
            'sell_price'   => $sellPrice,
            'unit_cogs'    => $unitCogs,
            'cost_source'  => $costSource,
            'missing_cost' => $missing,
            'margin'       => $margin,
            'margin_pct'   => $marginPct,
            'qty_sold'     => $qtySold,
            'revenue'      => $revenue,
            'cogs'         => $cogs,
            'gross_profit' => round($revenue - $cogs, 2),
            'ingredients'  => $ingredients,
        ];
    }

    private function salesByProduct(int $storeId, ?string $from, ?string $to)
    {
        return DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.status', 'completed')
            ->where('s.store_location_id', $storeId)
            ->when($from, fn ($q) => $q->where('s.created_at', '>=', $from.' 00:00:00'))
            ->when($to, fn ($q) => $q->where('s.created_at', '<=', $to.' 23:59:59'))
            ->groupBy('si.product_id')
            ->selectRaw('si.product_id, SUM(si.qty) as qty_sold, SUM(si.line_total) as revenue')
            ->get()
            ->keyBy('product_id');
    }

    private function cogsByProduct(int $storeId, ?string $from, ?string $to)
    {
        return DB::table('inventory_consumptions as ic')
            ->join('sale_items as si', 'si.id', '=', 'ic.sale_item_id')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.status', 'completed')
            ->where('s.store_location_id', $storeId)
            ->whereNull('ic.reversed_at')
            ->when($from, fn ($q) => $q->where('s.created_at', '>=', $from.' 00:00:00'))
            ->when($to, fn ($q) => $q->where('s.created_at', '<=', $to.' 23:59:59'))
            ->groupBy('si.product_id')
            ->selectRaw('si.product_id, SUM(ic.qty * ic.unit_cost) as cogs')
            ->get()
            ->keyBy('product_id');
    }
}
