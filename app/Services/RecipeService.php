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
     * @param  array<int, array<int, float>>  $optionDeltasByProduct
     *         finished product_id => [ingredient_product_id => qty_delta per finished unit]
     *         Signed: +5 more ice, -10 no ice. Applied after the recipe base, floored at 0.
     * @return array<int, float>  ingredient_product_id => total qty in stock units
     */
    public function aggregateIngredientNeeds(
        Collection $recipesByProduct,
        array $lineQtyByProduct,
        array $optionDeltasByProduct = []
    ): array {
        $needs = [];

        foreach ($lineQtyByProduct as $productId => $soldQty) {
            $productId = (int) $productId;
            $soldQty = (float) $soldQty;
            $recipe = $recipesByProduct->get($productId);
            $deltas = $optionDeltasByProduct[$productId] ?? [];

            $lineNeeds = $this->ingredientNeedsForSaleLine($recipe, $soldQty, $deltas);

            foreach ($lineNeeds as $ingId => $qty) {
                $needs[$ingId] = ($needs[$ingId] ?? 0.0) + $qty;
            }
        }

        return $needs;
    }

    /**
     * Ingredient consumption for one finished sale line (stock units), after
     * applying option qty deltas on top of the recipe.
     *
     * @param  array<int, float>  $qtyDeltasPerUnit  ingredient_id => signed delta per 1 finished
     * @return array<int, float>  ingredient_id => total qty to consume for this line
     */
    public function ingredientNeedsForSaleLine(
        ?ProductRecipe $recipe,
        float $finishedQtySold,
        array $qtyDeltasPerUnit = []
    ): array {
        $needs = [];
        $applied = [];

        if ($recipe) {
            foreach ($recipe->items as $line) {
                $ingId = (int) $line->ingredient_product_id;
                $base = $this->stockQtyForSale($line, $finishedQtySold);
                $delta = ((float) ($qtyDeltasPerUnit[$ingId] ?? 0)) * $finishedQtySold;
                $qty = max(0.0, $base + $delta);
                $applied[$ingId] = true;

                if ($qty > 1e-9) {
                    $needs[$ingId] = ($needs[$ingId] ?? 0.0) + $qty;
                }
            }
        }

        // Option pointed at an ingredient the recipe does not list → add it.
        foreach ($qtyDeltasPerUnit as $ingId => $deltaPerUnit) {
            $ingId = (int) $ingId;
            if (isset($applied[$ingId])) {
                continue;
            }

            $qty = max(0.0, ((float) $deltaPerUnit) * $finishedQtySold);
            if ($qty > 1e-9) {
                $needs[$ingId] = ($needs[$ingId] ?? 0.0) + $qty;
            }
        }

        return $needs;
    }

    /**
     * Collapse selected option values into per-ingredient qty deltas in the
     * ingredient's **stock unit** (so they can be added to recipe needs).
     *
     * qty_delta may be entered in Ml while stock is L — converted here, same
     * as product recipe lines.
     *
     * @param  iterable<ProductOptionValue>  $selectedValues
     * @return array<int, float> ingredient_product_id => signed qty_delta (stock unit)
     */
    public function qtyDeltasFromOptions(iterable $selectedValues): array
    {
        $deltas = [];

        foreach ($selectedValues as $val) {
            $group = $val->group ?? null;
            if (! $group || ! $group->ingredient_product_id) {
                continue;
            }

            $raw = (float) ($val->qty_delta ?? 0);
            if (abs($raw) < 1e-12) {
                continue;
            }

            $ingredient = $group->ingredient;
            $stockUnit = $ingredient?->unit;
            $fromUnit = $val->qtyDeltaUnit ?? $stockUnit;

            if ($stockUnit && $fromUnit) {
                try {
                    $raw = UnitConversionService::convert($raw, $fromUnit, $stockUnit);
                } catch (\InvalidArgumentException $e) {
                    abort(422, $e->getMessage());
                }
            }

            $ingId = (int) $group->ingredient_product_id;
            $deltas[$ingId] = ($deltas[$ingId] ?? 0.0) + $raw;
        }

        return $deltas;
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
     * Same check as validateIngredientStock() but never aborts.
     *
     * Used for offline sales that are being synced: the customer already paid,
     * so we record the sale and report which ingredients went short instead of
     * rejecting it.
     *
     * @return array<int, array<string, mixed>> shortfall rows
     */
    public function collectIngredientShortfall(array $ingredientNeeds, int $storeId): array
    {
        $shortfall = [];

        foreach ($ingredientNeeds as $productId => $needQty) {
            $product = Product::find($productId);
            if (! $product || ! $product->isStockTracked()) {
                continue;
            }

            $available = InventoryService::sumQtyRemaining((int) $productId, $storeId);
            if ($available + 1e-9 < (float) $needQty) {
                $shortfall[] = [
                    'product_id'    => (int) $productId,
                    'product_name'  => $product->name,
                    'qty_sold'      => (float) $needQty,
                    'qty_available' => (float) $available,
                    'shortfall'     => round((float) $needQty - (float) $available, 4),
                    'kind'          => 'INGREDIENT',
                ];
            }
        }

        return $shortfall;
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
