<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StoreLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contract for `inventory:seed-cost-price`.
 *
 * The whole value of this command is what it refuses to copy. On the live data
 * ~96% of purchase records had unit_price identical to products.price — the
 * fingerprint of the sell-price bug. Seeding those would bake the error into
 * cost_price permanently AND hide it from the repair command, which detects the
 * bug by matching "cost == price".
 */
class SeedProductCostPriceTest extends TestCase
{
    use RefreshDatabase;

    private StoreLocation $store;

    private ?int $supplierId = null;

    private ?int $userId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = StoreLocation::create(['code' => 'MAIN', 'name' => 'Main Store']);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'store_location_id' => $this->store->id,
            'name'              => 'Widget',
            'sku'               => 'SKU-'.uniqid(),
            'price'             => 10000,
            'inventory_type'    => Product::INVENTORY_TYPE_STOCK,
        ], $attributes));
    }

    private function supplierId(): int
    {
        return $this->supplierId ??= DB::table('suppliers')->insertGetId([
            'name'       => 'Test Supplier',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function userId(): int
    {
        return $this->userId ??= User::create([
            'name'     => 'Buyer',
            'email'    => 'buyer-'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
        ])->id;
    }

    private function purchaseItem(Product $product, float $unitPrice, float $qty = 5, float $discount = 0): int
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_number'   => 'PO-'.uniqid(),
            'supplier_id'       => $this->supplierId(),
            'user_id'           => $this->userId(),
            'store_location_id' => $this->store->id,
            'order_date'        => now()->toDateString(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return DB::table('purchase_items')->insertGetId([
            'purchase_id'  => $purchaseId,
            'product_id'   => $product->id,
            'qty_order'    => $qty,
            'qty_received' => $qty,
            'unit_price'   => $unitPrice,
            'discount'     => $discount,
            'tax'          => 0,
            'line_total'   => $unitPrice * $qty,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function test_it_seeds_cost_from_a_genuine_purchase_price(): void
    {
        $product = $this->product(['price' => 10000, 'cost_price' => null]);
        $this->purchaseItem($product, 3500);

        $this->artisan('inventory:seed-cost-price --apply')->assertExitCode(0);

        $this->assertEquals(3500, (float) $product->fresh()->cost_price);
    }

    /**
     * The core protection: a purchase priced at the sell price is almost
     * certainly the old bug, not a real supplier price.
     */
    public function test_purchase_records_equal_to_sell_price_are_not_trusted(): void
    {
        $product = $this->product(['price' => 10000, 'cost_price' => null]);
        $this->purchaseItem($product, 10000);

        $this->artisan('inventory:seed-cost-price --apply')->assertExitCode(0);

        $this->assertNull(
            $product->fresh()->cost_price,
            'Cost identical to the sell price must not be copied into cost_price.'
        );
    }

    /** Escape hatch stays available for genuine at-cost selling, but opt-in only. */
    public function test_equal_to_price_records_can_be_forced_in_explicitly(): void
    {
        $product = $this->product(['price' => 10000, 'cost_price' => null]);
        $this->purchaseItem($product, 10000);

        $this->artisan('inventory:seed-cost-price --apply --include-equal-to-price')
            ->assertExitCode(0);

        $this->assertEquals(10000, (float) $product->fresh()->cost_price);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $product = $this->product(['price' => 10000, 'cost_price' => null]);
        $this->purchaseItem($product, 3500);

        $this->artisan('inventory:seed-cost-price')->assertExitCode(0);

        $this->assertNull($product->fresh()->cost_price);
    }

    public function test_existing_cost_price_is_preserved_unless_overwrite(): void
    {
        $product = $this->product(['price' => 10000, 'cost_price' => 2000]);
        $this->purchaseItem($product, 3500);

        $this->artisan('inventory:seed-cost-price --apply')->assertExitCode(0);
        $this->assertEquals(2000, (float) $product->fresh()->cost_price);

        $this->artisan('inventory:seed-cost-price --apply --overwrite')->assertExitCode(0);
        $this->assertEquals(3500, (float) $product->fresh()->cost_price);
    }

    /** Line-level discount is spread across units to get a net unit cost. */
    public function test_line_discount_is_amortised_per_unit(): void
    {
        $product = $this->product(['price' => 10000, 'cost_price' => null]);
        $this->purchaseItem($product, 4000, qty: 4, discount: 400);

        $this->artisan('inventory:seed-cost-price --apply')->assertExitCode(0);

        // 4000 - (400 / 4) = 3900
        $this->assertEquals(3900, (float) $product->fresh()->cost_price);
    }

    public function test_average_strategy_weights_by_quantity(): void
    {
        $product = $this->product(['price' => 10000, 'cost_price' => null]);
        $this->purchaseItem($product, 2000, qty: 1);
        $this->purchaseItem($product, 4000, qty: 3);

        $this->artisan('inventory:seed-cost-price --apply --strategy=average')->assertExitCode(0);

        // (2000*1 + 4000*3) / 4 = 3500
        $this->assertEquals(3500, (float) $product->fresh()->cost_price);
    }

    public function test_latest_strategy_takes_the_most_recent_purchase(): void
    {
        $product = $this->product(['price' => 10000, 'cost_price' => null]);
        $this->purchaseItem($product, 2000);
        $this->purchaseItem($product, 4444);

        $this->artisan('inventory:seed-cost-price --apply --strategy=latest')->assertExitCode(0);

        $this->assertEquals(4444, (float) $product->fresh()->cost_price);
    }

    /**
     * End to end: seeding then repairing should leave consumptions valued at the
     * real cost, which is the entire point of both commands.
     */
    public function test_seed_then_repair_restores_true_cogs(): void
    {
        $product = $this->product(['price' => 10000, 'cost_price' => null]);
        $this->purchaseItem($product, 3000);

        // A historical opening-stock layer valued at the sell price.
        $layerId = DB::table('inventory_layers')->insertGetId([
            'product_id'        => $product->id,
            'store_location_id' => $this->store->id,
            'source_type'       => 'ADD_PRODUCT',
            'unit_landed_cost'  => 10000,
            'unit_cost'         => 10000,
            'qty_initial'       => 5,
            'qty_remaining'     => 3,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
        DB::table('inventory_consumptions')->insert([
            'product_id'        => $product->id,
            'store_location_id' => $this->store->id,
            'layer_id'          => $layerId,
            'qty'               => 2,
            'unit_cost'         => 10000,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->artisan('inventory:seed-cost-price --apply')->assertExitCode(0);
        $this->artisan('inventory:repair-cost-basis --apply')->assertExitCode(0);

        $this->assertEquals(3000, (float) $product->fresh()->cost_price);
        $this->assertEquals(
            3000,
            (float) DB::table('inventory_consumptions')->where('layer_id', $layerId)->value('unit_cost'),
            'COGS should reflect the real purchase cost, not the sell price.'
        );
    }
}
