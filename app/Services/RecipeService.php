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
}
