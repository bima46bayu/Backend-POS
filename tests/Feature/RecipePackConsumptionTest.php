<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeItem;
use App\Models\StoreLocation;
use App\Models\Unit;
use App\Services\InventoryService;
use App\Services\RecipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A recipe must be able to consume the CONTENTS of a pack.
 *
 * Straws are stocked in Pack (100 per pack) but a drink uses 1 Batang. Pack and
 * Batang are unrelated units to UnitConversionService (it only knows kg/g and
 * l/ml), so before pack_size was wired in here the conversion threw and
 * RecipeService turned it into a 422 that BLOCKED the sale outright.
 */
class RecipePackConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private StoreLocation $store;
    private Unit $pack;
    private Unit $batang;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = StoreLocation::create(['code' => 'MAIN', 'name' => 'Main']);
        $this->pack = Unit::create(['name' => 'Pack']);
        $this->batang = Unit::create(['name' => 'Batang']);
    }

    private function straws(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'store_location_id' => $this->store->id,
            'name'              => 'Sedotan',
            'sku'               => 'STR-'.uniqid(),
            'price'             => 0,
            'cost_price'        => 5000,   // per Pack
            'pack_size'         => 100,    // 100 Batang per Pack
            'pack_label'        => 'Batang',
            'unit_id'           => $this->pack->id,
            'inventory_type'    => Product::INVENTORY_TYPE_STOCK,
        ], $overrides));
    }

    private function drinkUsing(Product $ingredient, float $qty, ?Unit $unit): Product
    {
        $drink = Product::create([
            'store_location_id' => $this->store->id,
            'name'              => 'Es Teh',
            'sku'               => 'TEA-'.uniqid(),
            'price'             => 5000,
            'inventory_type'    => Product::INVENTORY_TYPE_STOCK,
        ]);

        $recipe = ProductRecipe::create([
            'product_id'        => $drink->id,
            'store_location_id' => $this->store->id,
            'is_active'         => true,
        ]);

        ProductRecipeItem::create([
            'recipe_id'             => $recipe->id,
            'ingredient_product_id' => $ingredient->id,
            'qty'                   => $qty,
            'unit_id'               => $unit?->id,
        ]);

        return $drink;
    }

    private function lineFor(Product $drink): ProductRecipeItem
    {
        return ProductRecipe::where('product_id', $drink->id)
            ->with('items.ingredient.unit', 'items.unit')
            ->firstOrFail()
            ->items
            ->first();
    }

    /** 1 Batang out of a 100-Pack is 0.01 Pack. */
    public function test_recipe_in_contents_unit_consumes_a_fraction_of_a_pack(): void
    {
        $straws = $this->straws();
        $drink = $this->drinkUsing($straws, 1, $this->batang);

        $qty = app(RecipeService::class)->lineQtyInStockUnit($this->lineFor($drink));

        $this->assertEqualsWithDelta(0.01, $qty, 0.000001);
    }

    /** A recipe written in the stock unit itself must pass through untouched. */
    public function test_recipe_in_pack_unit_is_not_converted(): void
    {
        $straws = $this->straws();
        $drink = $this->drinkUsing($straws, 2, $this->pack);

        $qty = app(RecipeService::class)->lineQtyInStockUnit($this->lineFor($drink));

        $this->assertEqualsWithDelta(2.0, $qty, 0.000001, '2 Packs means 2 Packs.');
    }

    /**
     * pack_label left blank is common, so generic piece words must still resolve
     * rather than 422-ing at the till.
     */
    public function test_generic_pcs_resolves_when_pack_label_is_blank(): void
    {
        $pcs = Unit::create(['name' => 'Pcs']);
        $straws = $this->straws(['pack_label' => null]);
        $drink = $this->drinkUsing($straws, 5, $pcs);

        $qty = app(RecipeService::class)->lineQtyInStockUnit($this->lineFor($drink));

        $this->assertEqualsWithDelta(0.05, $qty, 0.000001);
    }

    /**
     * End-to-end: selling one drink must draw Rp 50 of COGS from the pack and
     * leave 99% of it on the shelf.
     */
    public function test_selling_a_drink_draws_one_straw_of_cogs(): void
    {
        $straws = $this->straws();
        $drink = $this->drinkUsing($straws, 1, $this->batang);

        app(InventoryService::class)->addInboundLayer([
            'product_id'        => $straws->id,
            'store_location_id' => $this->store->id,
            'qty'               => 1,      // 1 Pack
            'unit_cost'         => 5000,
            'source_type'       => 'GR',
        ]);

        $service = app(RecipeService::class);
        $recipes = $service->loadActiveForProducts([$drink->id], $this->store->id);
        $needs = $service->aggregateIngredientNeeds($recipes, [$drink->id => 1]);

        $this->assertEqualsWithDelta(0.01, $needs[$straws->id], 0.000001);

        // Must not report "out of stock" for a fraction of a pack.
        $service->validateIngredientStock($needs, $this->store->id);

        app(InventoryService::class)->consumeFIFOWithPricing([
            'product_id'        => $straws->id,
            'qty'               => $needs[$straws->id],
            'store_location_id' => $this->store->id,
            'sale_unit_price'   => 0,
        ]);

        $remaining = InventoryService::sumQtyRemaining($straws->id, $this->store->id);
        $this->assertEqualsWithDelta(0.99, $remaining, 0.000001);
    }

    /**
     * Regression guard: an unpacked ingredient with genuinely incompatible units
     * must still be rejected. The pack branch must not become a catch-all that
     * silently treats "Kg" as "1 piece".
     */
    public function test_incompatible_units_still_fail_for_unpacked_ingredient(): void
    {
        $kg = Unit::create(['name' => 'Kg']);
        $sugar = Product::create([
            'store_location_id' => $this->store->id,
            'name'              => 'Gula',
            'sku'               => 'SGR-'.uniqid(),
            'price'             => 0,
            'cost_price'        => 15000,
            'unit_id'           => $kg->id,
            'inventory_type'    => Product::INVENTORY_TYPE_STOCK,
        ]);

        $drink = $this->drinkUsing($sugar, 2, $this->batang);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(RecipeService::class)->lineQtyInStockUnit($this->lineFor($drink));
    }
}
