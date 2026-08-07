<?php

namespace App\Services;

use App\Models\Product;
use App\Models\RegisterSession;
use App\Models\StockWriteOff;
use App\Support\StockLedgerWriter as Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Records waste / spoiled / expired stock removals.
 *
 * Unlike a manual note at register close, this consumes real FIFO layers so
 * stock and cost stay correct.
 */
class StockWriteOffService
{
    /**
     * @param array{product_id:int,qty:float,reason:string,store_location_id:int,user_id?:int|null,note?:string|null} $p
     */
    public function record(array $p): StockWriteOff
    {
        $productId = (int) $p['product_id'];
        $storeId = (int) $p['store_location_id'];
        $qty = (float) $p['qty'];
        $reason = strtoupper((string) $p['reason']);
        $userId = $p['user_id'] ?? null;
        $note = $p['note'] ?? null;

        if ($qty <= 1e-9) {
            throw new RuntimeException('Qty harus lebih dari 0.');
        }

        if (! in_array($reason, StockWriteOff::REASONS, true)) {
            throw new RuntimeException('Alasan write-off tidak valid.');
        }

        $product = Product::find($productId);
        if (! $product) {
            throw new RuntimeException('Produk tidak ditemukan.');
        }

        if (! $product->isStockTracked()) {
            throw new RuntimeException("Produk '{$product->name}' non-stock, tidak bisa di-write-off.");
        }

        return DB::transaction(function () use ($product, $productId, $storeId, $qty, $reason, $userId, $note) {
            $taken = $this->consumeFifo($productId, $storeId, $qty, $reason, $userId, $note, $product->name);

            $totalCost = 0.0;
            foreach ($taken as $row) {
                $totalCost += $row['qty'] * $row['unit_cost'];
            }
            $unitCost = $qty > 0 ? $totalCost / $qty : 0.0;

            InventoryService::syncLegacyProductStock($productId, $storeId);

            return StockWriteOff::create([
                'store_location_id' => $storeId,
                'product_id' => $productId,
                'user_id' => $userId,
                'register_session_id' => $this->openRegisterSessionId($storeId, $userId),
                'reason' => $reason,
                'qty' => $qty,
                'unit_cost' => round($unitCost, 2),
                'total_cost' => round($totalCost, 2),
                'note' => $note,
            ]);
        });
    }

    /**
     * Consume oldest layers first; records consumption + ledger rows per layer.
     *
     * @return array<int, array{layer_id:int, qty:float, unit_cost:float}>
     */
    protected function consumeFifo(
        int $productId,
        int $storeId,
        float $qty,
        string $reason,
        ?int $userId,
        ?string $note,
        string $productName
    ): array {
        $need = (float) $qty;
        $eps = 1e-9;
        $taken = [];

        while ($need > $eps) {
            $layer = DB::table('inventory_layers')
                ->where('product_id', $productId)
                ->where('store_location_id', $storeId)
                ->where('qty_remaining', '>', 0)
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $layer) {
                break;
            }

            $take = min($need, (float) $layer->qty_remaining);
            $cost = (float) ($layer->unit_landed_cost ?? $layer->unit_cost ?? 0);

            if (Schema::hasTable('inventory_consumptions')) {
                DB::table('inventory_consumptions')->insert([
                    'product_id' => $productId,
                    'store_location_id' => $storeId,
                    'sale_id' => null,
                    'sale_item_id' => null,
                    'layer_id' => $layer->id,
                    'qty' => $take,
                    'unit_cost' => $cost,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('inventory_layers')->where('id', $layer->id)->update([
                'qty_remaining' => DB::raw('qty_remaining - ' . (float) $take),
                'updated_at' => now(),
            ]);

            Ledger::write([
                'product_id' => $productId,
                'direction' => -1,
                'qty' => $take,
                'unit_cost' => $cost,
                'layer_id' => (int) $layer->id,
                'store_location_id' => $storeId,
                'ref_type' => $reason,
                'note' => $note ?: ('Write-off ' . $reason),
            ]);

            $taken[] = [
                'layer_id' => (int) $layer->id,
                'qty' => $take,
                'unit_cost' => $cost,
            ];

            $need -= $take;
        }

        if ($need > $eps) {
            throw new RuntimeException(
                "Stok {$productName} tidak cukup untuk write-off (kurang {$need})."
            );
        }

        return $taken;
    }

    protected function openRegisterSessionId(int $storeId, ?int $userId): ?int
    {
        if (! $userId) {
            return null;
        }

        return RegisterSession::query()
            ->where('cashier_id', $userId)
            ->where('store_location_id', $storeId)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->value('id');
    }
}
