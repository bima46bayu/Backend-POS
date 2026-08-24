<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StoreLocation;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end cover for the cost_price plumbing: creating a product with opening
 * stock through the API must value the layer at the submitted cost, not at the
 * sell price, and must produce exactly one ledger row.
 */
class ProductCostPriceApiTest extends TestCase
{
    use RefreshDatabase;

    private StoreLocation $store;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = StoreLocation::create(['code' => 'MAIN', 'name' => 'Main Store']);
        $this->admin = User::factory()->create([
            'role'              => User::ROLE_ADMIN,
            'store_location_id' => $this->store->id,
        ]);
    }

    public function test_creating_product_with_opening_stock_values_layer_at_cost_not_sell_price(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/products', [
            'name'              => 'Kaos Logo',
            'price'             => 120000,
            'cost_price'        => 45000,
            'stock'             => 10,
            'inventory_type'    => 'stock',
            'store_location_id' => $this->store->id,
        ])->assertCreated();

        $productId = $response->json('product.id') ?? $response->json('product.data.id');
        $this->assertNotNull($productId);

        $this->assertDatabaseHas('products', ['id' => $productId, 'cost_price' => 45000]);

        $layer = DB::table('inventory_layers')->where('product_id', $productId)->first();
        $this->assertNotNull($layer, 'Opening stock should create a layer.');
        $this->assertSame(45000.0, (float) $layer->unit_landed_cost);
        $this->assertNotEquals(120000.0, (float) $layer->unit_landed_cost);
        $this->assertSame(10.0, (float) $layer->qty_remaining);

        // Exactly one ledger row, written by the service (not the controller).
        $ledger = DB::table('stock_ledger')->where('product_id', $productId)->get();
        $this->assertCount(1, $ledger);
        $this->assertSame(45000.0, (float) $ledger->first()->unit_cost);

        // Legacy mirror column stays in step.
        $this->assertSame(10.0, (float) Product::find($productId)->stock);
        $this->assertSame(10.0, (float) InventoryService::sumQtyRemaining($productId, $this->store->id));
    }

    public function test_cost_price_is_optional_and_absent_cost_does_not_fall_back_to_price(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/products', [
            'name'              => 'No Cost Widget',
            'price'             => 99000,
            'stock'             => 4,
            'inventory_type'    => 'stock',
            'store_location_id' => $this->store->id,
        ])->assertCreated();

        $productId = $response->json('product.id') ?? $response->json('product.data.id');

        $layer = DB::table('inventory_layers')->where('product_id', $productId)->first();
        $this->assertSame(0.0, (float) $layer->unit_landed_cost, 'Missing cost must be 0, never the sell price.');
    }

    public function test_non_stock_product_with_stock_input_creates_no_layer(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/products', [
            'name'              => 'Photobooth Service',
            'price'             => 50000,
            'cost_price'        => 10000,
            'stock'             => 8,
            'inventory_type'    => 'non_stock',
            'store_location_id' => $this->store->id,
        ])->assertCreated();

        $productId = $response->json('product.id') ?? $response->json('product.data.id');

        $this->assertSame(0, DB::table('inventory_layers')->where('product_id', $productId)->count());
        $this->assertSame(0, DB::table('stock_ledger')->where('product_id', $productId)->count());
    }

    public function test_cost_price_can_be_updated(): void
    {
        $product = Product::create([
            'store_location_id' => $this->store->id,
            'name'              => 'Widget',
            'sku'               => 'SKU-UPD-1',
            'price'             => 25000,
            'cost_price'        => 8000,
            'inventory_type'    => Product::INVENTORY_TYPE_STOCK,
        ]);

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/products/'.$product->id, [
            'name'           => 'Widget',
            'sku'            => 'SKU-UPD-1',
            'price'          => 25000,
            'cost_price'     => 11500,
            'inventory_type' => 'stock',
        ])->assertOk();

        $this->assertSame(11500.0, (float) $product->fresh()->cost_price);
    }
}
