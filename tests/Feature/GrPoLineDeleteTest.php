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

class GrPoLineDeleteTest extends TestCase
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

    private function product(string $name = 'Gula'): Product
    {
        return Product::create([
            'store_location_id' => $this->store->id,
            'name' => $name,
            'sku' => 'SKU-'.uniqid(),
            'price' => 25000,
            'cost_price' => 10000,
            'inventory_type' => Product::INVENTORY_TYPE_STOCK,
        ]);
    }

    private function createApprovedPo(array $lines): array
    {
        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/purchases', [
            'supplier_id' => $this->supplierId,
            'store_location_id' => $this->store->id,
            'order_date' => now()->toDateString(),
            'items' => $lines,
        ])->assertCreated();

        $poId = (int) ($res->json('id') ?? $res->json('data.id'));
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/purchases/{$poId}/approve")
            ->assertOk();

        $items = Purchase::find($poId)->items()->orderBy('id')->get();

        return [
            'po_id' => $poId,
            'items' => $items,
        ];
    }

    private function receive(int $poId, array $lines): int
    {
        $res = $this->actingAs($this->admin, 'sanctum')->postJson("/api/purchases/{$poId}/receive", [
            'received_date' => now()->toDateString(),
            'items' => $lines,
        ])->assertOk();

        return (int) ($res->json('gr.id') ?? $res->json('gr_id'));
    }

    public function test_untouched_line_without_gr_is_soft_cancelled(): void
    {
        $product = $this->product();
        $po = $this->createApprovedPo([[
            'product_id' => $product->id,
            'qty_order' => 10,
            'unit_price' => 10000,
        ]]);
        $itemId = (int) $po['items'][0]->id;

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/purchases/{$po['po_id']}/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('item.status', 'cancelled');

        $this->assertDatabaseHas('purchase_items', ['id' => $itemId, 'status' => 'cancelled']);
        $this->assertEquals(0, (float) DB::table('purchase_items')->where('id', $itemId)->value('qty_received'));
        $this->assertEquals(0, (float) Purchase::find($po['po_id'])->grand_total);
        $this->assertSame('canceled', Purchase::find($po['po_id'])->status);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'purchase.line_cancel',
            'subject_id' => (string) $itemId,
        ]);
    }

    public function test_untouched_gr_line_cascades_layer_delete_and_zeros_stock(): void
    {
        $keep = $this->product('Keep');
        $drop = $this->product('Drop');
        $po = $this->createApprovedPo([
            ['product_id' => $keep->id, 'qty_order' => 5, 'unit_price' => 8000],
            ['product_id' => $drop->id, 'qty_order' => 4, 'unit_price' => 12000],
        ]);
        $keepId = (int) $po['items'][0]->id;
        $dropId = (int) $po['items'][1]->id;

        $grId = $this->receive($po['po_id'], [
            ['purchase_item_id' => $keepId, 'qty_received' => 5],
            ['purchase_item_id' => $dropId, 'qty_received' => 4],
        ]);

        $this->assertSame(5.0, InventoryService::sumQtyRemaining($keep->id, $this->store->id));
        $this->assertSame(4.0, InventoryService::sumQtyRemaining($drop->id, $this->store->id));

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/purchases/{$po['po_id']}/items/{$dropId}")
            ->assertOk()
            ->assertJsonPath('item.status', 'cancelled');

        $this->assertDatabaseHas('purchase_items', ['id' => $dropId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('purchase_items', ['id' => $keepId]);
        $this->assertSame('open', Purchase::find($po['po_id'])->items()->where('id', $keepId)->value('status'));
        $this->assertEquals(0, (float) DB::table('purchase_items')->where('id', $dropId)->value('qty_received'));
        $this->assertSame(5.0, InventoryService::sumQtyRemaining($keep->id, $this->store->id));
        $this->assertSame(0.0, InventoryService::sumQtyRemaining($drop->id, $this->store->id));
        $this->assertSame(1, DB::table('goods_receipt_items')->where('goods_receipt_id', $grId)->count());
        $this->assertDatabaseHas('goods_receipts', ['id' => $grId]);
        $this->assertEquals(40000, (float) Purchase::find($po['po_id'])->grand_total);
        $this->assertStringContainsString('layer removed', (string) DB::table('purchase_items')->where('id', $dropId)->value('cancelled_note'));
    }

    public function test_consumed_line_cannot_be_deleted(): void
    {
        $product = $this->product();
        $po = $this->createApprovedPo([[
            'product_id' => $product->id,
            'qty_order' => 10,
            'unit_price' => 10000,
        ]]);
        $itemId = (int) $po['items'][0]->id;
        $this->receive($po['po_id'], [
            ['purchase_item_id' => $itemId, 'qty_received' => 10],
        ]);

        app(InventoryService::class)->consumeFIFOWithPricing([
            'product_id' => $product->id,
            'store_location_id' => $this->store->id,
            'qty' => 3,
            'sale_unit_price' => 25000,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/purchases/{$po['po_id']}/items/{$itemId}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'LINE_CONSUMED')
            ->assertJsonPath('recommended_action', 'reverse');

        $this->assertSame('open', Purchase::find($po['po_id'])->items()->where('id', $itemId)->value('status'));
        $this->assertSame(1, DB::table('inventory_layers')->where('product_id', $product->id)->count());
        $this->assertSame(7.0, InventoryService::sumQtyRemaining($product->id, $this->store->id));
    }

    public function test_show_marks_consumed_line_as_not_deletable(): void
    {
        $product = $this->product();
        $po = $this->createApprovedPo([[
            'product_id' => $product->id,
            'qty_order' => 4,
            'unit_price' => 10000,
        ]]);
        $itemId = (int) $po['items'][0]->id;
        $this->receive($po['po_id'], [
            ['purchase_item_id' => $itemId, 'qty_received' => 4],
        ]);
        app(InventoryService::class)->consumeFIFOWithPricing([
            'product_id' => $product->id,
            'store_location_id' => $this->store->id,
            'qty' => 4,
            'sale_unit_price' => 25000,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/purchases/{$po['po_id']}")
            ->assertOk()
            ->assertJsonPath('items.0.delete_lock.deletable', false)
            ->assertJsonPath('items.0.delete_lock.recommended_action', 'cost_adjustment');
    }

    public function test_kasir_cannot_cancel_po_line(): void
    {
        $product = $this->product();
        $po = $this->createApprovedPo([[
            'product_id' => $product->id,
            'qty_order' => 2,
            'unit_price' => 10000,
        ]]);
        $itemId = (int) $po['items'][0]->id;

        $this->actingAs($this->kasir, 'sanctum')
            ->deleteJson("/api/purchases/{$po['po_id']}/items/{$itemId}")
            ->assertForbidden();
    }
}
