<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeItem;
use App\Models\Unit;
use App\Services\UnitConversionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductRecipeController extends Controller
{
    public function index(Request $request)
    {
        $storeId = $this->resolveStoreIdFromRequest($request);

        if ($storeId === null) {
            return response()->json(['data' => []]);
        }

        $recipes = ProductRecipe::query()
            ->with(['product', 'items.ingredient.unit', 'items.unit'])
            ->where('store_location_id', $storeId)
            ->orderBy('id')
            ->get();

        return response()->json($recipes);
    }

    public function store(Request $request)
    {
        $storeId = $this->resolveStoreIdFromRequest(
            $request,
            $request->filled('store_location_id') ? (int) $request->input('store_location_id') : null
        );

        if ($storeId === null) {
            abort(422, 'Store wajib dipilih.');
        }

        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'is_active'  => 'boolean',
            'items'      => 'required|array|min:1',
            'items.*.ingredient_product_id' => 'required|integer|exists:products,id|distinct',
            'items.*.qty' => 'required|numeric|gt:0',
            'items.*.unit_id' => 'nullable|integer|exists:units,id',
        ]);

        $product = Product::findOrFail($data['product_id']);
        if ($product->store_location_id !== null && (int) $product->store_location_id !== $storeId) {
            abort(422, 'Produk finished good harus milik cabang yang sama.');
        }

        $this->validateItems($data['items'], $storeId, (int) $data['product_id']);

        $recipe = DB::transaction(function () use ($data, $storeId) {
            $recipe = ProductRecipe::updateOrCreate(
                [
                    'product_id'        => (int) $data['product_id'],
                    'store_location_id' => $storeId,
                ],
                [
                    'is_active' => $data['is_active'] ?? true,
                ]
            );

            ProductRecipeItem::where('recipe_id', $recipe->id)->delete();

            foreach ($data['items'] as $row) {
                $ingredient = Product::with('unit')->findOrFail((int) $row['ingredient_product_id']);
                $unitId = $this->resolveLineUnitId($row, $ingredient);
                $this->validateLineConversion((float) $row['qty'], $unitId, $ingredient);

                ProductRecipeItem::create([
                    'recipe_id'             => $recipe->id,
                    'ingredient_product_id' => (int) $row['ingredient_product_id'],
                    'qty'                   => (float) $row['qty'],
                    'unit_id'               => $unitId,
                ]);
            }

            return $recipe->load(['product', 'items.ingredient.unit', 'items.unit']);
        });

        return response()->json($recipe, 201);
    }

    public function show(ProductRecipe $productRecipe)
    {
        $this->authorizeRecipe($productRecipe);

        return response()->json(
            $productRecipe->load(['product', 'items.ingredient'])
        );
    }

    public function update(Request $request, ProductRecipe $productRecipe)
    {
        $this->authorizeRecipe($productRecipe);

        $data = $request->validate([
            'is_active' => 'sometimes|boolean',
            'items'     => 'sometimes|array|min:1',
            'items.*.ingredient_product_id' => 'required_with:items|integer|exists:products,id|distinct',
            'items.*.qty' => 'required_with:items|numeric|gt:0',
            'items.*.unit_id' => 'nullable|integer|exists:units,id',
        ]);

        if (isset($data['items'])) {
            $this->validateItems(
                $data['items'],
                (int) $productRecipe->store_location_id,
                (int) $productRecipe->product_id
            );
        }

        $recipe = DB::transaction(function () use ($productRecipe, $data) {
            if (array_key_exists('is_active', $data)) {
                $productRecipe->is_active = (bool) $data['is_active'];
                $productRecipe->save();
            }

            if (isset($data['items'])) {
                ProductRecipeItem::where('recipe_id', $productRecipe->id)->delete();
                foreach ($data['items'] as $row) {
                    $ingredient = Product::with('unit')->findOrFail((int) $row['ingredient_product_id']);
                    $unitId = $this->resolveLineUnitId($row, $ingredient);
                    $this->validateLineConversion((float) $row['qty'], $unitId, $ingredient);

                    ProductRecipeItem::create([
                        'recipe_id'             => $productRecipe->id,
                        'ingredient_product_id' => (int) $row['ingredient_product_id'],
                        'qty'                   => (float) $row['qty'],
                        'unit_id'               => $unitId,
                    ]);
                }
            }

            return $productRecipe->fresh(['product', 'items.ingredient.unit', 'items.unit']);
        });

        return response()->json($recipe);
    }

    public function destroy(ProductRecipe $productRecipe)
    {
        $this->authorizeRecipe($productRecipe);
        $productRecipe->delete();

        return response()->noContent();
    }

    protected function authorizeRecipe(ProductRecipe $recipe): void
    {
        $this->authorizeStoreAccess(request()->user(), (int) $recipe->store_location_id);
    }

    protected function validateItems(array $items, int $storeId, int $finishedProductId): void
    {
        foreach ($items as $row) {
            $ingredientId = (int) $row['ingredient_product_id'];

            if ($ingredientId === $finishedProductId) {
                abort(422, 'Produk tidak boleh menjadi bahan resep dirinya sendiri.');
            }

            $ingredient = Product::find($ingredientId);
            if (! $ingredient) {
                abort(422, 'Bahan resep tidak ditemukan.');
            }

            if (! $ingredient->isStockTracked()) {
                abort(422, "Bahan {$ingredient->name} harus bertipe stok (stock).");
            }

            if ($ingredient->store_location_id !== null && (int) $ingredient->store_location_id !== $storeId) {
                abort(422, "Bahan {$ingredient->name} tidak tersedia di cabang ini.");
            }
        }
    }

    protected function resolveLineUnitId(array $row, Product $ingredient): int
    {
        $unitId = ! empty($row['unit_id'])
            ? (int) $row['unit_id']
            : (int) ($ingredient->unit_id ?? 0);

        if ($unitId <= 0) {
            abort(422, "Bahan {$ingredient->name} belum punya satuan. Atur satuan di katalog produk.");
        }

        return $unitId;
    }

    protected function validateLineConversion(float $qty, int $recipeUnitId, Product $ingredient): void
    {
        $recipeUnit = Unit::find($recipeUnitId);
        $productUnit = $ingredient->unit ?? Unit::find($ingredient->unit_id);

        if (! $recipeUnit || ! $productUnit) {
            abort(422, 'Satuan resep atau satuan stok bahan tidak ditemukan.');
        }

        try {
            UnitConversionService::convert($qty, $recipeUnit, $productUnit);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }
}
