<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\StoreLocation;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GrPoLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private StoreLocation $store;

    private User $admin;

    private User $kasir;

    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = StoreLocation::create(['code' => 'MAIN', 'name' => 'Main Store']);
        $this->admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'store_location_id' => $this->store->id,
        ]);
        $this->kasir = User::factory()->create([
            'role' => User::ROLE_KASIR,
            'store_location_id' => $this->store->id,
        ]);
        $this->supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'PT Sumber',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'store_location_id' => $this->store->id,
            'name' => 'Gula',
            'sku' => 'SKU-'.uniqid(),
            'price' => 25000,
            'cost_price' => 10000,
            'inventory_type' => Product::INVENTORY_TYPE_STOCK,
        ], $attributes));
    }

    private function createApprovedPo(Product $product, float $unitPrice = 10000, int $qty = 10): array
    {
        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/purchases', [
            'supplier_id' => $this->supplierId,
            'store_location_id' => $this->store->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product->id,
                'qty_order' => $qty,
                'unit_price' => $unitPrice,
            ]],
        ])->assertCreated();

        $poId = (int) ($res->json('id') ?? $res->json('data.id'));
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/purchases/{$poId}/approve")
            ->assertOk();

        $itemId = (int) Purchase::find($poId)->items()->value('id');

        return ['po_id' => $poId, 'item_id' => $itemId];
    }

    private function receive(int $poId, int $itemId, int $qty): int
    {
        $res = $this->actingAs($this->admin, 'sanctum')->postJson("/api/purchases/{$poId}/receive", [
            'received_date' => now()->toDateString(),
            'items' => [[
                'purchase_item_id' => $itemId,
                'qty_received' => $qty,
            ]],
        ])->assertOk();

        return (int) ($res->json('gr.id') ?? $res->json('gr_id'));
    }

    public function test_po_price_is_editable_before_gr(): void
    {
        $product = $this->product();
        $po = $this->createApprovedPo($product, 10000, 10);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/purchases/{$po['po_id']}", [
                'items' => [[
                    'id' => $po['item_id'],
                    'unit_price' => 12000,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('price_editable', true);

        $this->assertEquals(12000, (float) DB::table('purchase_items')->where('id', $po['item_id'])->value('unit_price'));
    }

    public function test_po_price_cannot_silently_overwrite_a_received_layer(): void
    {
        $product = $this->product();
        $po = $this->createApprovedPo($product, 10000, 10);
        $grId = $this->receive($po['po_id'], $po['item_id'], 10);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/purchases/{$po['po_id']}", [
                'items' => [[
                    'id' => $po['item_id'],
                    'unit_price' => 15000,
                ]],
            ])
            ->assertStatus(422);

        $this->assertEquals(10000, (float) DB::table('purchase_items')->where('id', $po['item_id'])->value('unit_price'));

        $layer = DB::table('inventory_layers')->where('source_type', 'GR')->first();
        $this->assertNotNull($layer);
        $this->assertEquals(10000, (float) $layer->unit_landed_cost);
        $this->assertSame('open', $layer->status);
        $this->assertGreaterThan(0, $grId);
    }

    public function test_unconsumed_gr_is_hard_deleted(): void
    {
        $product = $this->product();
        $po = $this->createApprovedPo($product, 10000, 10);
        $grId = $this->receive($po['po_id'], $po['item_id'], 10);

        $this->assertSame(10.0, InventoryService::sumQtyRemaining($product->id, $this->store->id));

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/receipts/{$grId}/void")
            ->assertOk()
            ->assertJsonPath('action', 'delete');

        $this->assertDatabaseMissing('goods_receipts', ['id' => $grId]);
        $this->assertSame(0, DB::table('inventory_layers')->where('product_id', $product->id)->count());
        $this->assertSame(0, DB::table('stock_ledger')->where('product_id', $product->id)->where('ref_type', 'GR')->count());
        $this->assertEquals(0, (float) DB::table('purchase_items')->where('id', $po['item_id'])->value('qty_received'));
        $this->assertSame('approved', Purchase::find($po['po_id'])->status);
        $this->assertSame(0.0, InventoryService::sumQtyRemaining($product->id, $this->store->id));

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/purchases/{$po['po_id']}")
            ->assertOk()
            ->assertJsonPath('price_editable', true);
    }

    public function test_partially_consumed_gr_reverses_remaining_and_flags_consumed(): void
    {
        $product = $this->product();
        $po = $this->createApprovedPo($product, 10000, 10);
        $grId = $this->receive($po['po_id'], $po['item_id'], 10);

        $layer = DB::table('inventory_layers')->where('source_type', 'GR')->first();
        app(InventoryService::class)->consumeFIFOWithPricing([
            'product_id' => $product->id,
            'store_location_id' => $this->store->id,
            'qty' => 4,
            'sale_id' => null,
            'sale_item_id' => null,
            'sale_unit_price' => 25000,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/receipts/{$grId}/void")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/receipts/{$grId}/void", ['reason' => 'Salah qty terima, sisa dikembalikan'])
            ->assertOk()
            ->assertJsonPath('action', 'reverse');

        $layer = DB::table('inventory_layers')->where('id', $layer->id)->first();
        $this->assertSame('reversed', $layer->status);
        $this->assertEquals(0.0, (float) $layer->qty_remaining);
        $this->assertEquals(6.0, (float) $layer->qty_reversed);
        $this->assertTrue((bool) $layer->consumed_review_flagged);

        $this->assertSame(1, DB::table('inventory_consumptions')->where('layer_id', $layer->id)->count());
        $this->assertEquals(4.0, (float) DB::table('inventory_consumptions')->where('layer_id', $layer->id)->sum('qty'));
        $this->assertSame(1, DB::table('stock_ledger')->where('ref_type', 'GR_REVERSAL')->count());
        $this->assertEquals(4.0, (float) DB::table('purchase_items')->where('id', $po['item_id'])->value('qty_received'));
        $this->assertSame('partially_received', Purchase::find($po['po_id'])->status);
        $this->assertNotNull(DB::table('goods_receipts')->where('id', $grId)->value('review_flagged_at'));
        $this->assertSame(0.0, InventoryService::sumQtyRemaining($product->id, $this->store->id));

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/receipts/{$grId}/cost-adjustments", [
                'reason' => 'Harga supplier beda',
                'new_unit_cost' => 8000,
            ])
            ->assertCreated();

        $shown = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/purchases/{$po['po_id']}")
            ->assertOk()
            ->assertJsonPath('price_editable', false)
            ->assertJsonPath('receipt_story.has_reversed', true)
            ->assertJsonPath('receipt_story.has_cost_adjustment', true);

        $item = $shown->json('items.0');
        $this->assertEquals(10000, (float) ($item['unit_price'] ?? 0));
        $this->assertEquals(4, (float) ($item['qty_received'] ?? 0));
        $this->assertEquals(6, (float) ($item['qty_reversed'] ?? 0));
        $this->assertEquals(8000, (float) ($item['adjusted_unit_cost'] ?? 0));
        $this->assertEquals(100000, (float) ($item['line_total'] ?? 0));
        $this->assertEquals(-8000, (float) ($item['cogs_delta'] ?? 0));
        $this->assertStringContainsString('PO tetap seperti order', (string) $shown->json('receipt_story.headline'));
        $this->assertEquals(10000, (float) DB::table('purchase_items')->where('id', $po['item_id'])->value('unit_price'));
    }

    public function test_fully_consumed_gr_cannot_be_voided_and_requires_cost_adjustment(): void
    {
        $product = $this->product();
        $po = $this->createApprovedPo($product, 10000, 5);
        $grId = $this->receive($po['po_id'], $po['item_id'], 5);

        app(InventoryService::class)->consumeFIFOWithPricing([
            'product_id' => $product->id,
            'store_location_id' => $this->store->id,
            'qty' => 5,
            'sale_unit_price' => 25000,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/receipts/{$grId}/void", ['reason' => 'Salah PO'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'GR_FULLY_CONSUMED')
            ->assertJsonPath('action', 'cost_adjustment');

        $this->assertDatabaseHas('goods_receipts', ['id' => $grId]);
        $layer = DB::table('inventory_layers')->where('source_type', 'GR')->first();
        $this->assertSame('closed', $layer->status);
        $this->assertEquals(0.0, (float) $layer->qty_remaining);
    }

    public function test_cost_adjustment_posts_cogs_delta_without_editing_the_original_consumption(): void
    {
        $product = $this->product();
        $po = $this->createApprovedPo($product, 10000, 5);
        $grId = $this->receive($po['po_id'], $po['item_id'], 5);

        app(InventoryService::class)->consumeFIFOWithPricing([
            'product_id' => $product->id,
            'store_location_id' => $this->store->id,
            'qty' => 5,
            'sale_unit_price' => 25000,
        ]);

        $consumptionCost = (float) DB::table('inventory_consumptions')->value('unit_cost');
        $this->assertEquals(10000, $consumptionCost);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/receipts/{$grId}/cost-adjustments", [
                'new_unit_cost' => 12000,
                'reason' => 'Invoice supplier lebih mahal',
            ])
            ->assertCreated();

        $adj = DB::table('cost_adjustments')->first();
        $this->assertNotNull($adj);
        $this->assertEquals(5.0, (float) $adj->qty_affected);
        $this->assertEquals(10000, (float) $adj->old_unit_cost);
        $this->assertEquals(12000, (float) $adj->new_unit_cost);
        $this->assertEquals(10000, (float) $adj->cogs_delta);
        $this->assertSame('Invoice supplier lebih mahal', $adj->reason);
        $this->assertEquals($this->admin->id, (int) $adj->created_by);

        $this->assertEquals(
            10000,
            (float) DB::table('inventory_consumptions')->value('unit_cost'),
            'Original consumption cost must stay snapshotted.'
        );
        $this->assertEquals(
            10000,
            (float) DB::table('inventory_layers')->value('unit_landed_cost'),
            'Original layer cost must stay snapshotted.'
        );
        $this->assertSame(1, DB::table('stock_ledger')->where('ref_type', 'COST_ADJUSTMENT')->count());
    }

    public function test_cost_adjustment_accepts_per_item_new_unit_cost(): void
    {
        $p1 = $this->product(['name' => 'Lope']);
        $p2 = $this->product(['name' => 'Wewewe']);

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/purchases', [
            'supplier_id' => $this->supplierId,
            'store_location_id' => $this->store->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $p1->id, 'qty_order' => 5, 'unit_price' => 10000],
                ['product_id' => $p2->id, 'qty_order' => 5, 'unit_price' => 20000],
            ],
        ])->assertCreated();

        $poId = (int) ($res->json('id') ?? $res->json('data.id'));
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/purchases/{$poId}/approve")
            ->assertOk();

        $items = Purchase::find($poId)->items()->orderBy('id')->get();
        $item1 = $items[0];
        $item2 = $items[1];

        $grRes = $this->actingAs($this->admin, 'sanctum')->postJson("/api/purchases/{$poId}/receive", [
            'received_date' => now()->toDateString(),
            'items' => [
                ['purchase_item_id' => $item1->id, 'qty_received' => 5],
                ['purchase_item_id' => $item2->id, 'qty_received' => 5],
            ],
        ])->assertOk();
        $grId = (int) ($grRes->json('gr.id') ?? $grRes->json('gr_id'));

        app(InventoryService::class)->consumeFIFOWithPricing([
            'product_id' => $p1->id,
            'store_location_id' => $this->store->id,
            'qty' => 5,
            'sale_unit_price' => 25000,
        ]);
        app(InventoryService::class)->consumeFIFOWithPricing([
            'product_id' => $p2->id,
            'store_location_id' => $this->store->id,
            'qty' => 5,
            'sale_unit_price' => 35000,
        ]);

        $layers = DB::table('inventory_layers')->where('source_type', 'GR')->orderBy('id')->get();
        $this->assertCount(2, $layers);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/receipts/{$grId}/cost-adjustments", [
                'reason' => 'Harga beda per SKU',
                'lines' => [
                    ['layer_id' => $layers[0]->id, 'new_unit_cost' => 12000],
                    ['layer_id' => $layers[1]->id, 'new_unit_cost' => 18000],
                ],
            ])
            ->assertCreated();

        $adj = DB::table('cost_adjustments')->orderBy('product_id')->get();
        $this->assertCount(2, $adj);
        $byProduct = $adj->keyBy('product_id');
        $this->assertEquals(12000, (float) $byProduct[$p1->id]->new_unit_cost);
        $this->assertEquals(18000, (float) $byProduct[$p2->id]->new_unit_cost);
        $this->assertEquals(10000, (float) $byProduct[$p1->id]->cogs_delta);
        $this->assertEquals(-10000, (float) $byProduct[$p2->id]->cogs_delta);
        $this->assertSame(2, DB::table('stock_ledger')->where('ref_type', 'COST_ADJUSTMENT')->count());

        $shown = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/receipts/{$grId}")
            ->assertOk();
        $layersJson = collect($shown->json('lifecycle.layers'))->keyBy('product_id');
        $this->assertEquals(10000, (float) $layersJson[$p1->id]['unit_cost']);
        $this->assertEquals(12000, (float) $layersJson[$p1->id]['adjusted_unit_cost']);
        $this->assertEquals(20000, (float) $layersJson[$p2->id]['unit_cost']);
        $this->assertEquals(18000, (float) $layersJson[$p2->id]['adjusted_unit_cost']);
        $this->assertCount(2, $shown->json('lifecycle.cost_adjustments'));
    }

    public function test_cost_adjustment_requires_reason(): void
    {
        $product = $this->product();
        $po = $this->createApprovedPo($product, 10000, 2);
        $grId = $this->receive($po['po_id'], $po['item_id'], 2);
        app(InventoryService::class)->consumeFIFOWithPricing([
            'product_id' => $product->id,
            'store_location_id' => $this->store->id,
            'qty' => 2,
            'sale_unit_price' => 25000,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/receipts/{$grId}/cost-adjustments", [
                'new_unit_cost' => 8000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_fully_consumed_wrong_po_is_flagged_for_manual_review_without_stock_changes(): void
    {
        $product = $this->product();
        $po = $this->createApprovedPo($product, 10000, 3);
        $grId = $this->receive($po['po_id'], $po['item_id'], 3);
        app(InventoryService::class)->consumeFIFOWithPricing([
            'product_id' => $product->id,
            'store_location_id' => $this->store->id,
            'qty' => 3,
            'sale_unit_price' => 25000,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/receipts/{$grId}/review", [
                'reason' => 'GR ini untuk PO yang salah, sales sudah jalan',
            ])
            ->assertOk();

        $gr = DB::table('goods_receipts')->where('id', $grId)->first();
        $this->assertNotNull($gr->review_flagged_at);
        $this->assertSame('GR ini untuk PO yang salah, sales sudah jalan', $gr->review_reason);
        $this->assertEquals($this->admin->id, (int) $gr->review_flagged_by);
        $this->assertSame('posted', $gr->status);
        $this->assertSame(1, DB::table('inventory_layers')->where('source_type', 'GR')->count());

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/receipts?review_flagged=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $grId)
            ->assertJsonPath('data.0.review_flagged', true)
            ->assertJsonPath('data.0.review_reason', 'GR ini untuk PO yang salah, sales sudah jalan');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/receipts/{$grId}/review/resolve")
            ->assertOk();

        $this->assertNull(DB::table('goods_receipts')->where('id', $grId)->value('review_flagged_at'));

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/receipts?review_flagged=1')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_kasir_cannot_void_or_cost_adjust(): void
    {
        $product = $this->product();
        $po = $this->createApprovedPo($product, 10000, 2);
        $grId = $this->receive($po['po_id'], $po['item_id'], 2);

        $this->actingAs($this->kasir, 'sanctum')
            ->postJson("/api/receipts/{$grId}/void")
            ->assertForbidden();

        $this->actingAs($this->kasir, 'sanctum')
            ->postJson("/api/receipts/{$grId}/cost-adjustments", [
                'new_unit_cost' => 1,
                'reason' => 'tidak boleh',
            ])
            ->assertForbidden();
    }

    public function test_fifo_does_not_draw_from_a_reversed_layer(): void
    {
        $product = $this->product();
        $po = $this->createApprovedPo($product, 10000, 10);
        $grId = $this->receive($po['po_id'], $po['item_id'], 10);

        app(InventoryService::class)->consumeFIFOWithPricing([
            'product_id' => $product->id,
            'store_location_id' => $this->store->id,
            'qty' => 4,
            'sale_unit_price' => 25000,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/receipts/{$grId}/void", ['reason' => 'Reverse sisa'])
            ->assertOk();

        $this->expectException(\RuntimeException::class);
        app(InventoryService::class)->consumeFIFOWithPricing([
            'product_id' => $product->id,
            'store_location_id' => $this->store->id,
            'qty' => 1,
            'sale_unit_price' => 25000,
        ]);
    }
}
