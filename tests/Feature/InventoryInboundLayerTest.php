<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StoreLocation;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contract for the single inbound-layer path.
 *
 * There used to be four implementations (Support\InventoryQuick,
 * Services\StockService, Services\StockReconciliationService and inline code in
 * StockReconciliationController). They disagreed about cost basis, ledger
 * writes, products.stock syncing and inventory_type. These tests pin the
 * behaviour so the copies can't creep back.
 */
class InventoryInboundLayerTest extends TestCase
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

    private function service(): InventoryService
    {
        return app(InventoryService::class);
    }

    /**
     * The regression that started all this: `price` is the SELL price. Writing
     * it into unit_landed_cost made COGS equal revenue and zeroed margin.
     */
    public function test_sell_price_is_never_used_as_the_cost_basis(): void
    {
        $product = $this->product(['price' => 25000, 'cost_price' => null]);

        $layerId = $this->service()->addInboundLayer([
            'product_id'        => $product->id,
            'qty'               => 3,
            'store_location_id' => $this->store->id,
            'source_type'       => 'ADD_PRODUCT',
        ]);

        $layer = DB::table('inventory_layers')->find($layerId);

        $this->assertSame(0.0, (float) $layer->unit_landed_cost, 'Unknown cost must be 0, not the sell price.');
        $this->assertSame(0.0, (float) $layer->unit_cost);
        $this->assertNotEquals(25000.0, (float) $layer->unit_landed_cost);
    }

    public function test_cost_price_column_supplies_the_cost_when_caller_passes_none(): void
    {
        $product = $this->product(['price' => 25000, 'cost_price' => 9000]);

        $layerId = $this->service()->addInboundLayer([
            'product_id'        => $product->id,
            'qty'               => 2,
            'store_location_id' => $this->store->id,
        ]);

        $layer = DB::table('inventory_layers')->find($layerId);
        $this->assertSame(9000.0, (float) $layer->unit_landed_cost);
    }

    public function test_explicit_cost_wins_over_cost_price_and_landed_cost_includes_tax_and_extras(): void
    {
        $product = $this->product(['cost_price' => 9000]);

        $layerId = $this->service()->addInboundLayer([
            'product_id'        => $product->id,
            'qty'               => 1,
            'unit_buy'          => 10000,
            'unit_tax'          => 1100,
            'unit_other_cost'   => 400,
            'store_location_id' => $this->store->id,
            'source_type'       => 'GR',
        ]);

        $layer = DB::table('inventory_layers')->find($layerId);
        $this->assertSame(11500.0, (float) $layer->unit_landed_cost);
        $this->assertSame(10000.0, (float) $layer->unit_price);
    }

    /**
     * The guard used to live in each caller, so any new caller silently created
     * phantom layers for services/non-stock items.
     */
    public function test_non_stock_products_never_get_a_layer(): void
    {
        $product = $this->product(['inventory_type' => Product::INVENTORY_TYPE_NON_STOCK]);

        $layerId = $this->service()->addInboundLayer([
            'product_id'        => $product->id,
            'qty'               => 5,
            'unit_buy'          => 1000,
            'store_location_id' => $this->store->id,
        ]);

        $this->assertNull($layerId);
        $this->assertSame(0, DB::table('inventory_layers')->where('product_id', $product->id)->count());
        $this->assertSame(0, DB::table('stock_ledger')->where('product_id', $product->id)->count());
    }

    public function test_zero_or_negative_qty_and_missing_product_are_no_ops(): void
    {
        $product = $this->product();

        $this->assertNull($this->service()->addInboundLayer([
            'product_id' => $product->id, 'qty' => 0, 'store_location_id' => $this->store->id,
        ]));
        $this->assertNull($this->service()->addInboundLayer([
            'product_id' => $product->id, 'qty' => -4, 'store_location_id' => $this->store->id,
        ]));
        $this->assertNull($this->service()->addInboundLayer([
            'product_id' => 999999, 'qty' => 5, 'store_location_id' => $this->store->id,
        ]));

        $this->assertSame(0, DB::table('inventory_layers')->count());
    }

    /**
     * Callers used to hand-roll ~50 lines of stock_ledger insertion each, with
     * different ref_types and duplicate guards. The service owns it now.
     */
    public function test_inbound_writes_exactly_one_matching_ledger_row(): void
    {
        $product = $this->product();

        $layerId = $this->service()->addInboundLayer([
            'product_id'        => $product->id,
            'qty'               => 4,
            'unit_buy'          => 2500,
            'store_location_id' => $this->store->id,
            'source_type'       => 'GR',
            'source_id'         => 77,
            'note'              => 'GR TEST-1',
        ]);

        $rows = DB::table('stock_ledger')->where('product_id', $product->id)->get();
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame(1, (int) $row->direction);
        $this->assertSame(4.0, (float) $row->qty);
        $this->assertSame(2500.0, (float) $row->unit_cost);
        $this->assertSame(10000.0, (float) $row->subtotal_cost);
        $this->assertSame('GR', $row->ref_type);
        $this->assertSame(77, (int) $row->ref_id);
        $this->assertSame($layerId, (int) $row->layer_id);
        $this->assertSame($this->store->id, (int) $row->store_location_id);
    }

    /**
     * Stock opname records the layer as ADJUSTMENT_IN but reports the ledger
     * under RECON_ADJUST, so the two must be independently settable.
     */
    public function test_ledger_ref_type_can_differ_from_layer_source_type(): void
    {
        $product = $this->product();

        $layerId = $this->service()->addInboundLayer([
            'product_id'        => $product->id,
            'qty'               => 2,
            'unit_cost'         => 1500,
            'store_location_id' => $this->store->id,
            'source_type'       => 'ADJUSTMENT_IN',
            'source_id'         => 12,
            'ledger_ref_type'   => 'RECON_ADJUST',
            'ledger_ref_id'     => 12,
        ]);

        $this->assertSame('ADJUSTMENT_IN', DB::table('inventory_layers')->find($layerId)->source_type);
        $this->assertSame('RECON_ADJUST', DB::table('stock_ledger')->where('product_id', $product->id)->value('ref_type'));
    }

    /**
     * GR previously never synced the legacy mirror column, so GR-received stock
     * read stale in any query that wasn't store-scoped.
     */
    public function test_inbound_syncs_the_legacy_products_stock_mirror(): void
    {
        $product = $this->product();

        $this->service()->addInboundLayer([
            'product_id'        => $product->id,
            'qty'               => 7,
            'unit_buy'          => 1000,
            'store_location_id' => $this->store->id,
            'source_type'       => 'GR',
        ]);

        $this->assertSame(7.0, (float) $product->fresh()->stock);

        $this->service()->addInboundLayer([
            'product_id'        => $product->id,
            'qty'               => 3,
            'unit_buy'          => 1000,
            'store_location_id' => $this->store->id,
            'source_type'       => 'GR',
        ]);

        $this->assertSame(10.0, (float) $product->fresh()->stock);
        $this->assertSame(10.0, (float) InventoryService::sumQtyRemaining($product->id, $this->store->id));
    }

    /**
     * End-to-end proof of the original bug: cost must survive into
     * inventory_consumptions, which is what COGS reporting reads.
     */
    public function test_consumption_records_the_real_cost_not_the_sell_price(): void
    {
        $product = $this->product(['price' => 25000, 'cost_price' => 8000]);

        $this->service()->addInboundLayer([
            'product_id'        => $product->id,
            'qty'               => 5,
            'store_location_id' => $this->store->id,
            'source_type'       => 'ADD_PRODUCT',
        ]);

        $this->service()->consumeFIFOWithPricing([
            'product_id'        => $product->id,
            'qty'               => 2,
            'store_location_id' => $this->store->id,
            'sale_unit_price'   => 25000,
        ]);

        $consumption = DB::table('inventory_consumptions')->where('product_id', $product->id)->first();
        $this->assertSame(8000.0, (float) $consumption->unit_cost);
        $this->assertSame(3.0, (float) InventoryService::sumQtyRemaining($product->id, $this->store->id));
    }
}
