<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StoreLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contract for `inventory:repair-cost-basis`, which fixes historical layers that
 * recorded the SELL price as the inventory cost.
 *
 * The risky part of a repair command is over-reach, so most of these tests are
 * about what it must NOT touch: GR layers, already-correct layers, and applied
 * reconciliations.
 */
class RepairInventoryCostBasisTest extends TestCase
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
            'name'              => 'Widget',
            'sku'               => 'SKU-'.uniqid(),
            'price'             => 25000,
            'inventory_type'    => Product::INVENTORY_TYPE_STOCK,
        ], $attributes));
    }

    /** Insert a layer directly, imitating the historical buggy writes. */
    private function layer(Product $product, float $cost, string $source, float $qty = 4): int
    {
        return DB::table('inventory_layers')->insertGetId([
            'product_id'        => $product->id,
            'store_location_id' => $this->store->id,
            'source_type'       => $source,
            'unit_landed_cost'  => $cost,
            'unit_cost'         => $cost,
            'estimated_cost'    => $cost * $qty,
            'qty_initial'       => $qty,
            'qty_remaining'     => $qty,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function test_dry_run_reports_but_changes_nothing(): void
    {
        $product = $this->product(['price' => 25000, 'cost_price' => 9000]);
        $layerId = $this->layer($product, 25000, 'ADD_PRODUCT');

        $this->artisan('inventory:repair-cost-basis')
            ->assertExitCode(0);

        $this->assertEquals(
            25000,
            (float) DB::table('inventory_layers')->where('id', $layerId)->value('unit_landed_cost'),
            'Dry run must not write.'
        );
    }

    public function test_apply_rewrites_layer_to_the_real_cost(): void
    {
        $product = $this->product(['price' => 25000, 'cost_price' => 9000]);
        $layerId = $this->layer($product, 25000, 'ADD_PRODUCT', 4);

        $this->artisan('inventory:repair-cost-basis --apply')->assertExitCode(0);

        $layer = DB::table('inventory_layers')->where('id', $layerId)->first();
        $this->assertEquals(9000, (float) $layer->unit_landed_cost);
        $this->assertEquals(9000, (float) $layer->unit_cost);
        $this->assertEquals(9000 * 4, (float) $layer->estimated_cost, 'estimated_cost must be recomputed.');
    }

    /**
     * COGS is read from inventory_consumptions, so repairing only the layer would
     * leave the reported margin just as wrong as before.
     */
    public function test_apply_also_repairs_consumptions_and_ledger(): void
    {
        $product = $this->product(['price' => 25000, 'cost_price' => 9000]);
        $layerId = $this->layer($product, 25000, 'ADD_PRODUCT', 4);

        DB::table('inventory_consumptions')->insert([
            'product_id'        => $product->id,
            'store_location_id' => $this->store->id,
            'layer_id'          => $layerId,
            'qty'               => 2,
            'unit_cost'         => 25000,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        DB::table('stock_ledger')->insert([
            'product_id'        => $product->id,
            'store_location_id' => $this->store->id,
            'layer_id'          => $layerId,
            'direction'         => 1,
            'qty'               => 4,
            'unit_cost'         => 25000,
            'subtotal_cost'     => 100000,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->artisan('inventory:repair-cost-basis --apply --include-consumed')->assertExitCode(0);

        $this->assertEquals(
            9000,
            (float) DB::table('inventory_consumptions')->where('layer_id', $layerId)->value('unit_cost')
        );

        $ledger = DB::table('stock_ledger')->where('layer_id', $layerId)->first();
        $this->assertEquals(9000, (float) $ledger->unit_cost);
        $this->assertEquals(9000 * 4, (float) $ledger->subtotal_cost);
    }

    /**
     * GR layers were built from a real purchase price. If a supplier price
     * happens to equal the sell price, that's a coincidence, not the bug.
     */
    public function test_goods_receipt_layers_are_never_touched(): void
    {
        $product = $this->product(['price' => 25000, 'cost_price' => 9000]);
        $layerId = $this->layer($product, 25000, 'GR');

        $this->artisan('inventory:repair-cost-basis --apply')->assertExitCode(0);

        $this->assertEquals(
            25000,
            (float) DB::table('inventory_layers')->where('id', $layerId)->value('unit_landed_cost'),
            'GR layers must be left alone.'
        );
    }

    public function test_layers_already_costed_correctly_are_left_alone(): void
    {
        $product = $this->product(['price' => 25000, 'cost_price' => 9000]);
        $layerId = $this->layer($product, 9000, 'ADD_PRODUCT');

        $this->artisan('inventory:repair-cost-basis --apply')->assertExitCode(0);

        $this->assertEquals(
            9000,
            (float) DB::table('inventory_layers')->where('id', $layerId)->value('unit_landed_cost')
        );
    }

    /**
     * Without a known cost there is nothing to write. Guessing (e.g. halving the
     * price) would invent financial data, so the layer is skipped by default.
     */
    public function test_products_without_a_known_cost_are_skipped_by_default(): void
    {
        $product = $this->product(['price' => 25000, 'cost_price' => null]);
        $layerId = $this->layer($product, 25000, 'ADD_PRODUCT');

        $this->artisan('inventory:repair-cost-basis --apply')->assertExitCode(0);

        $this->assertEquals(
            25000,
            (float) DB::table('inventory_layers')->where('id', $layerId)->value('unit_landed_cost')
        );

        // ...unless the operator explicitly asks for the 0 = "unknown" normalisation.
        $this->artisan('inventory:repair-cost-basis --apply --zero-unknown')->assertExitCode(0);

        $this->assertEquals(
            0,
            (float) DB::table('inventory_layers')->where('id', $layerId)->value('unit_landed_cost')
        );
    }

    public function test_product_and_store_filters_scope_the_repair(): void
    {
        $target = $this->product(['price' => 25000, 'cost_price' => 9000]);
        $other  = $this->product(['price' => 25000, 'cost_price' => 9000]);

        $targetLayer = $this->layer($target, 25000, 'ADD_PRODUCT');
        $otherLayer  = $this->layer($other, 25000, 'ADD_PRODUCT');

        $this->artisan('inventory:repair-cost-basis --apply --product_id='.$target->id)
            ->assertExitCode(0);

        $this->assertEquals(9000, (float) DB::table('inventory_layers')->where('id', $targetLayer)->value('unit_landed_cost'));
        $this->assertEquals(25000, (float) DB::table('inventory_layers')->where('id', $otherLayer)->value('unit_landed_cost'));
    }

    /**
     * Draft stock-opname rows cache avg_cost from the layers, so they must be
     * re-derived. Applied ones are audit history and must not be rewritten.
     */
    public function test_draft_reconciliations_are_resynced_but_applied_ones_are_not(): void
    {
        $product = $this->product(['price' => 25000, 'cost_price' => 9000]);
        $this->layer($product, 25000, 'ADD_PRODUCT', 4);

        $draftId = DB::table('stock_reconciliations')->insertGetId([
            'name' => 'Draft', 'store_location_id' => $this->store->id,
            'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $appliedId = DB::table('stock_reconciliations')->insertGetId([
            'name' => 'Applied', 'store_location_id' => $this->store->id,
            'status' => 'APPLIED', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $draftItem = DB::table('stock_reconciliation_items')->insertGetId([
            'stock_reconciliation_id' => $draftId, 'product_id' => $product->id,
            'system_qty' => 4, 'avg_cost' => 25000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $appliedItem = DB::table('stock_reconciliation_items')->insertGetId([
            'stock_reconciliation_id' => $appliedId, 'product_id' => $product->id,
            'system_qty' => 4, 'avg_cost' => 25000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('inventory:repair-cost-basis --apply')->assertExitCode(0);

        $this->assertEquals(
            9000,
            (float) DB::table('stock_reconciliation_items')->where('id', $draftItem)->value('avg_cost'),
            'Draft avg_cost should follow the corrected layers.'
        );
        $this->assertEquals(
            25000,
            (float) DB::table('stock_reconciliation_items')->where('id', $appliedItem)->value('avg_cost'),
            'Applied recon is audit history and must not be rewritten.'
        );
    }
}
