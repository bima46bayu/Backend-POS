<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StoreLocation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saving a recipe that consumes "Pcs" of a Pack-stocked ingredient.
 *
 * Reported symptom: the recipe form offered Pcs for Sedotan (stock: Pack) but
 * saving returned 422 "Konversi satuan Pcs ke Pack tidak didukung". The POS
 * could consume it fine (1 Pcs = 1/100 Pack) — only the controller's save-time
 * check disagreed, because it called UnitConversionService directly instead of
 * the RecipeService rule. These tests lock the two paths together.
 */
class RecipePackUnitApiTest extends TestCase
{
    use RefreshDatabase;

    private StoreLocation $store;
    private Unit $pcs;
    private Unit $pack;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = StoreLocation::create(['code' => 'MAIN', 'name' => 'Main']);
        $this->pcs = Unit::create(['name' => 'Pcs', 'is_system' => true]);
        $this->pack = Unit::create(['name' => 'Pack', 'is_system' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role'              => User::ROLE_ADMIN,
            'store_location_id' => $this->store->id,
        ]);
    }

    private function straws(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'store_location_id' => $this->store->id,
            'name'              => 'Sedotan',
            'sku'               => 'STR-'.uniqid(),
            'price'             => 0,
            'cost_price'        => 5000,
            'pack_size'         => 100,
            'pack_label'        => null,   // matches production: label left blank
            'unit_id'           => $this->pack->id,
            'inventory_type'    => Product::INVENTORY_TYPE_STOCK,
        ], $overrides));
    }

    private function drink(): Product
    {
        return Product::create([
            'store_location_id' => $this->store->id,
            'name'              => 'Dawet Ori',
            'sku'               => 'DWT-'.uniqid(),
            'price'             => 8000,
            'inventory_type'    => Product::INVENTORY_TYPE_NON_STOCK,
        ]);
    }

    /** The exact request that used to 422. */
    public function test_recipe_accepts_pcs_for_a_pack_stocked_ingredient(): void
    {
        $straws = $this->straws();
        $drink = $this->drink();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/product-recipes', [
                'store_location_id' => $this->store->id,
                'product_id'        => $drink->id,
                'items'             => [
                    [
                        'ingredient_product_id' => $straws->id,
                        'qty'                   => 1,
                        'unit_id'               => $this->pcs->id,
                    ],
                ],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('product_recipe_items', [
            'ingredient_product_id' => $straws->id,
            'unit_id'               => $this->pcs->id,
            'qty'                   => 1,
        ]);
    }

    /** Recipes written in the stock unit itself must keep working. */
    public function test_recipe_accepts_the_stock_unit_directly(): void
    {
        $straws = $this->straws();
        $drink = $this->drink();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/product-recipes', [
                'store_location_id' => $this->store->id,
                'product_id'        => $drink->id,
                'items'             => [
                    [
                        'ingredient_product_id' => $straws->id,
                        'qty'                   => 2,
                        'unit_id'               => $this->pack->id,
                    ],
                ],
            ])
            ->assertCreated();
    }

    /**
     * Guard: the pack branch must not become a catch-all. An UNPACKED product
     * stocked in Pack has no ratio, so Pcs is still genuinely unconvertible.
     */
    public function test_pcs_is_still_rejected_when_ingredient_has_no_pack_size(): void
    {
        $straws = $this->straws(['pack_size' => null]);
        $drink = $this->drink();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/product-recipes', [
                'store_location_id' => $this->store->id,
                'product_id'        => $drink->id,
                'items'             => [
                    [
                        'ingredient_product_id' => $straws->id,
                        'qty'                   => 1,
                        'unit_id'               => $this->pcs->id,
                    ],
                ],
            ])
            ->assertStatus(422);
    }

    /** Mass/volume conversions must be untouched by all of this. */
    public function test_gram_into_kg_still_works(): void
    {
        $kg = Unit::create(['name' => 'Kg', 'is_system' => true]);
        $gram = Unit::create(['name' => 'Gram', 'is_system' => true]);

        $ice = Product::create([
            'store_location_id' => $this->store->id,
            'name'              => 'Es Batu',
            'sku'               => 'ICE-'.uniqid(),
            'price'             => 0,
            'unit_id'           => $kg->id,
            'inventory_type'    => Product::INVENTORY_TYPE_STOCK,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/product-recipes', [
                'store_location_id' => $this->store->id,
                'product_id'        => $this->drink()->id,
                'items'             => [
                    [
                        'ingredient_product_id' => $ice->id,
                        'qty'                   => 50,
                        'unit_id'               => $gram->id,
                    ],
                ],
            ])
            ->assertCreated();
    }
}
