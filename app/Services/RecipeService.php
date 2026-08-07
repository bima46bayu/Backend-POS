<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeItem;
use Illuminate\Support\Collection;
use App\Services\InventoryService;
use App\Services\UnitConversionService;

class RecipeService
{
    /** @return Collection<int, ProductRecipe> keyed by product_id */
    public function loadActiveForProducts(array $productIds, int $storeId): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return ProductRecipe::query()
            ->with(['items.ingredient.unit', 'items.unit'])
            ->where('store_location_id', $storeId)
            ->where('is_active', true)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');
    }

    /**
     * Ingredient qty in the product's stock unit for one finished unit sold.
     */
    public function lineQtyInStockUnit(ProductRecipeItem $line): float
    {
        $recipeUnit = $line->unit ?? $line->ingredient?->unit;
        $stockUnit = $line->ingredient?->unit;

        if (! $recipeUnit || ! $stockUnit) {
            abort(422, 'Resep memiliki bahan tanpa satuan yang valid.');
        }

        try {
            return UnitConversionService::convert(
                (float) $line->qty,
                $recipeUnit,
                $stockUnit
            );
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    /**
     * Total ingredient stock qty to consume for a sale line.
     */
    public function stockQtyForSale(ProductRecipeItem $line, float $finishedQtySold): float
    {
        return $this->lineQtyInStockUnit($line) * $finishedQtySold;
    }

    /**
     * Aggregate ingredient qty required for a cart (product_id => total qty needed).
     *
     * @param  array<int, float>  $lineQtyByProduct  finished product_id => sold qty
     * @return array<int, float>  ingredient_product_id => total qty in stock units
     */
    public function aggregateIngredientNeeds(Collection $recipesByProduct, array $lineQtyByProduct): array
    {
        $needs = [];

        foreach ($lineQtyByProduct as $productId => $soldQty) {
            $recipe = $recipesByProduct->get((int) $productId);
            if (! $recipe) {
                continue;
            }

            foreach ($recipe->items as $line) {
                $pid = (int) $line->ingredient_product_id;
                $needs[$pid] = ($needs[$pid] ?? 0.0) + $this->stockQtyForSale($line, (float) $soldQty);
            }
        }

        return $needs;
    }

    public function validateIngredientStock(array $ingredientNeeds, int $storeId): void
    {
        foreach ($ingredientNeeds as $productId => $needQty) {
            $product = Product::find($productId);
            if (! $product || ! $product->isStockTracked()) {
                abort(422, 'Bahan resep tidak valid atau bukan produk stok.');
            }

            $available = InventoryService::sumQtyRemaining((int) $productId, $storeId);
            if ($available + 1e-9 < (float) $needQty) {
                abort(422, "Stok bahan {$product->name} tidak cukup (butuh {$needQty}, tersisa {$available})");
            }
        }
    }

    /**
     * How many finished units can be made from current ingredient stock (FIFO layers).
     *
     * @param  array<int>  $productIds
     * @return array<int, array{qty:int, bottleneck:?string}> keyed by finished product_id
     */
    public function availableToMakeMap(array $productIds, int $storeId): array
    {
        $recipes = $this->loadActiveForProducts($productIds, $storeId);
        if ($recipes->isEmpty()) {
            return [];
        }

        $ingredientIds = [];
        foreach ($recipes as $recipe) {
            foreach ($recipe->items as $line) {
                $ingredientIds[] = (int) $line->ingredient_product_id;
            }
        }
        $ingredientIds = array_values(array_unique(array_filter($ingredientIds)));

        $stockByIngredient = [];
        if ($ingredientIds !== []) {
            if (\Illuminate\Support\Facades\Schema::hasTable('inventory_layers')) {
                $rows = \Illuminate\Support\Facades\DB::table('inventory_layers')
                    ->selectRaw('product_id, COALESCE(SUM(qty_remaining), 0) as qty')
                    ->where('store_location_id', $storeId)
                    ->whereIn('product_id', $ingredientIds)
                    ->groupBy('product_id')
                    ->get();
                foreach ($rows as $row) {
                    $stockByIngredient[(int) $row->product_id] = (float) $row->qty;
                }
            } else {
                foreach ($ingredientIds as $ingId) {
                    $stockByIngredient[$ingId] = InventoryService::sumQtyRemaining($ingId, $storeId);
                }
            }
        }

        $out = [];
        foreach ($recipes as $productId => $recipe) {
            if ($recipe->items->isEmpty()) {
                continue;
            }

            $minMake = null;
            $bottleneck = null;

            foreach ($recipe->items as $line) {
                $ingId = (int) $line->ingredient_product_id;
                $ingName = $line->ingredient?->name ?? ('#'.$ingId);

                try {
                    $needPer = $this->lineQtyInStockUnit($line);
                } catch (\Throwable $e) {
                    $minMake = 0;
                    $bottleneck = $ingName;
                    break;
                }

                if ($needPer <= 1e-12) {
                    continue;
                }

                $available = (float) ($stockByIngredient[$ingId] ?? 0.0);
                $can = (int) floor($available / $needPer + 1e-12);

                if ($minMake === null || $can < $minMake) {
                    $minMake = $can;
                    $bottleneck = $ingName;
                }
            }

            if ($minMake === null) {
                continue;
            }

            $out[(int) $productId] = [
                'qty' => max(0, $minMake),
                'bottleneck' => $bottleneck,
            ];
        }

        return $out;
    }
}
