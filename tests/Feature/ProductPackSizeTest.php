<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StoreLocation;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pack stocking: buy a Pack of 100 straws for Rp 5.000, stock in Packs, consume
 * per Batang via a recipe.
 *
 * The invariant these tests defend: stock, cost and purchasing all live in the
 * product's STOCK UNIT (Pack). `pack_size` is not a cost divisor — it only tells
 * a recipe how finely one pack can be consumed. Dividing cost by pack_size is
 * the 5000 -> 50 corruption that these tests exist to prevent from returning.
 */
class ProductPackSizeTest extends TestCase
{
    use RefreshDatabase;

    private StoreLocation $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = StoreLocation::create(['code' => 'MAIN', 'name' => 'Main Store']);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'store_location_id' => $this->store->id,
            'name'              => 'Sedotan',
            'sku'               => 'SKU-'.uniqid(),
            'price'             => 500,
            'inventory_type'    => Product::INVENTORY_TYPE_STOCK,
        ], $attributes));
    }

    // ---------- model maths ----------

    public function test_contents_convert_to_fractions_of_a_stock_unit(): void
    {
        $p = $this->product(['pack_size' => 100, 'cost_price' => 5000]);

        // 2 straws out of a 100-pack = 0.02 Pack.
        $this->assertSame(0.02, $p->contentsToStockUnits(2));
        $this->assertSame(2.0, $p->stockUnitsToContents(0.02));
        $this->assertTrue($p->isPacked());
    }

    /** The whole point: the pack price is NOT divided when stored. */
    public function test_cost_basis_stays_the_pack_price(): void
    {
        $p = $this->product(['pack_size' => 100, 'cost_price' => 5000]);

        $this->assertSame(5000.0, $p->costBasis(), 'Cost is per Pack and must not be divided.');
        $this->assertSame(50.0, $p->costPerContentsUnit(), 'Per-straw cost is derived, not stored.');
    }

    /** A "pack" of 1 is not a pack; treating it as one invites divide-by-no-op. */
    public function test_pack_size_of_one_or_zero_means_unpacked(): void
    {
        foreach ([null, 0, 1] as $size) {
            $p = $this->product(['pack_size' => $size]);

            $this->assertFalse($p->isPacked(), "pack_size={$size} should not count as packed");
            $this->assertSame(3.0, $p->contentsToStockUnits(3), 'Qty must pass through untouched.');
        }
    }

    // ---------- inbound service ----------

    /**
     * Receiving 2 Packs must book 2 stock units at the full pack price. If a
     * pack conversion ever creeps back into the inbound path, cost lands at 50
     * and this fails.
     */
    public function test_inbound_keeps_qty_and_cost_in_stock_units(): void
    {
        $p = $this->product(['pack_size' => 100, 'cost_price' => 5000]);

        $layerId = app(InventoryService::class)->addInboundLayer([
            'product_id'        => $p->id,
            'store_location_id' => $this->store->id,
            'qty'               => 2,      // 2 Packs
            'unit_cost'         => 5000,   // Rp 5.000 per Pack
            'source_type'       => 'GR',
        ]);

        $layer = DB::table('inventory_layers')->where('id', $layerId)->first();

        $this->assertEquals(2, (float) $layer->qty_initial, 'Stock is counted in Packs.');
        $this->assertEquals(5000, (float) $layer->unit_landed_cost, 'Cost stays the pack price.');

        // The ledger must agree, or profit reporting drifts from the layers.
        $ledger = DB::table('stock_ledger')->where('layer_id', $layerId)->first();
        $this->assertEquals(2, (float) $ledger->qty);
        $this->assertEquals(5000, (float) $ledger->unit_cost);
    }

    /** Unpacked products must behave exactly as before this feature existed. */
    public function test_unpacked_product_inbound_is_unchanged(): void
    {
        $p = $this->product(['cost_price' => 1200]);

        $layerId = app(InventoryService::class)->addInboundLayer([
            'product_id'        => $p->id,
            'store_location_id' => $this->store->id,
            'qty'               => 5,
            'unit_cost'         => 1200,
            'source_type'       => 'GR',
        ]);

        $layer = DB::table('inventory_layers')->where('id', $layerId)->first();
        $this->assertEquals(5, (float) $layer->qty_initial);
        $this->assertEquals(1200, (float) $layer->unit_landed_cost);
    }

    /**
     * The margin outcome that motivated all of this: a drink that uses one straw
     * must absorb Rp 50 of COGS, taken as 1/100th of a Rp 5.000 pack.
     */
    public function test_consuming_one_straw_costs_one_hundredth_of_a_pack(): void
    {
        $pack = Unit::create(['name' => 'Pack']);

        $p = $this->product([
            'pack_size'  => 100,
            'pack_label' => 'Batang',
            'cost_price' => 5000,
            'unit_id'    => $pack->id,
        ]);

        app(InventoryService::class)->addInboundLayer([
            'product_id'        => $p->id,
            'store_location_id' => $this->store->id,
            'qty'               => 1,       // 1 Pack
            'unit_cost'         => 5000,    // Rp 5.000
            'source_type'       => 'GR',
        ]);

        // A recipe consuming 1 Batang = 0.01 Pack.
        app(InventoryService::class)->consumeFIFOWithPricing([
            'product_id'        => $p->id,
            'qty'               => $p->contentsToStockUnits(1),
            'store_location_id' => $this->store->id,
            'sale_unit_price'   => 500,
        ]);

        $cogs = (float) DB::table('inventory_consumptions')
            ->where('product_id', $p->id)
            ->sum(DB::raw('qty * unit_cost'));

        $this->assertEqualsWithDelta(50.0, $cogs, 0.0001, 'One straw costs Rp 50.');

        // And 99% of the pack is still on hand.
        $remaining = InventoryService::sumQtyRemaining($p->id, $this->store->id);
        $this->assertEqualsWithDelta(0.99, $remaining, 0.0001);
    }

    // ---------- API ----------

    private function admin(): User
    {
        return User::factory()->create([
            'role'              => User::ROLE_ADMIN,
            'store_location_id' => $this->store->id,
        ]);
    }

    /**
     * `cost_price` is stored verbatim. For a pack-stocked product that figure IS
     * the pack price, so any rescaling here would understate COGS by pack_size×.
     */
    public function test_api_stores_cost_per_stock_unit_and_never_rescales_it(): void
    {
        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/products', [
            'name'              => 'Sedotan',
            'price'             => 500,
            'cost_price'        => 5000,  // per Pack
            'pack_size'         => 100,   // 100 Batang inside
            'pack_label'        => 'Batang',
            'stock'             => 2,
            'inventory_type'    => 'stock',
            'store_location_id' => $this->store->id,
        ])->assertSuccessful();

        $product = Product::where('name', 'Sedotan')->firstOrFail();
        $this->assertEquals(5000, (float) $product->cost_price, 'Stored verbatim, per Pack.');
        $this->assertEquals(100, (float) $product->pack_size);
        $this->assertSame('Batang', $product->pack_label);

        // Opening stock is valued at the same per-stock-unit cost.
        $layer = DB::table('inventory_layers')->where('product_id', $product->id)->first();
        $this->assertEquals(5000, (float) $layer->unit_landed_cost);
    }

    public function test_api_normalises_meaningless_pack_size_to_null(): void
    {
        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/products', [
            'name'              => 'Biasa',
            'price'             => 500,
            'cost_price'        => 300,
            'pack_size'         => 1,
            'pack_label'        => 'Pack',
            'inventory_type'    => 'stock',
            'store_location_id' => $this->store->id,
        ])->assertSuccessful();

        $product = Product::where('name', 'Biasa')->firstOrFail();
        $this->assertNull($product->pack_size);
        $this->assertNull($product->pack_label, 'Label without a pack size is noise.');
        $this->assertEquals(300, (float) $product->cost_price);
    }
}
