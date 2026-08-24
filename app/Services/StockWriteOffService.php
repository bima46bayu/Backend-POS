<?php

namespace App\Services;

use App\Models\Product;
use App\Models\RegisterSession;
use App\Models\StockWriteOff;
use App\Models\Unit;
use App\Support\StockLedgerWriter as Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Records waste / spoiled / expired stock removals.
 *
 * Rows saved together share a `batch_uid` so one "Catat Waste" reads as a single
 * document with many product lines.
 *
 * Flow:
 *  1. createDraft / createBatch — save editable rows, stock untouched
 *  2. updateDraft / updateBatch — change qty/reason/product while still draft
 *  3. submit / submitBatch — consume FIFO layers, lock the rows
 *  4. deleteDraft / deleteBatch — remove unsubmitted drafts
 */
class StockWriteOffService
{
    /**
     * @param array{product_id:int,qty:float,reason:string,store_location_id:int,user_id?:int|null,note?:string|null,qty_unit_id?:int|null,batch_uid?:string|null} $p
     */
    public function createDraft(array $p): StockWriteOff
    {
        [$product, $qty, $reason, $storeId, $userId, $note, $qtyUnitId] = $this->validateInput($p);

        return StockWriteOff::create([
            'store_location_id' => $storeId,
            'batch_uid' => $p['batch_uid'] ?? $this->newBatchUid(),
            'product_id' => (int) $product->id,
            'user_id' => $userId,
            'register_session_id' => $this->openRegisterSessionId($storeId, $userId),
            'reason' => $reason,
            'qty' => $qty,
            'qty_unit_id' => $qtyUnitId,
            'unit_cost' => 0,
            'total_cost' => 0,
            'note' => $note,
            'status' => StockWriteOff::STATUS_DRAFT,
            'submitted_at' => null,
            'submitted_by' => null,
        ]);
    }

    /**
     * @param array{product_id?:int,qty?:float,reason?:string,note?:string|null,qty_unit_id?:int|null} $p
     */
    public function updateDraft(StockWriteOff $writeOff, array $p): StockWriteOff
    {
        if (! $writeOff->isDraft()) {
            throw new RuntimeException('Write-off sudah di-submit, tidak bisa diubah.');
        }

        $productId = array_key_exists('product_id', $p)
            ? (int) $p['product_id']
            : (int) $writeOff->product_id;
        $qty = array_key_exists('qty', $p) ? (float) $p['qty'] : (float) $writeOff->qty;
        $reason = array_key_exists('reason', $p)
            ? strtoupper((string) $p['reason'])
            : (string) $writeOff->reason;
        $note = array_key_exists('note', $p) ? ($p['note'] ?? null) : $writeOff->note;
        $qtyUnitId = array_key_exists('qty_unit_id', $p)
            ? ($p['qty_unit_id'] !== null && $p['qty_unit_id'] !== '' ? (int) $p['qty_unit_id'] : null)
            : $writeOff->qty_unit_id;

        if ($qty <= 1e-9) {
            throw new RuntimeException('Qty harus lebih dari 0.');
        }
        if (! in_array($reason, StockWriteOff::REASONS, true)) {
            throw new RuntimeException('Alasan write-off tidak valid.');
        }

        $product = Product::with('unit')->find($productId);
        if (! $product) {
            throw new RuntimeException('Produk tidak ditemukan.');
        }
        if (! $product->isStockTracked()) {
            throw new RuntimeException("Produk '{$product->name}' non-stock, tidak bisa di-write-off.");
        }

        $qtyUnitId = $this->normalizeQtyUnitId($product, $qtyUnitId);

        $writeOff->fill([
            'product_id' => $productId,
            'qty' => $qty,
            'qty_unit_id' => $qtyUnitId,
            'reason' => $reason,
            'note' => $note,
        ]);
        $writeOff->save();

        return $writeOff->fresh(['product.unit', 'qtyUnit', 'user']);
    }

    public function submit(StockWriteOff $writeOff, ?int $userId = null): StockWriteOff
    {
        if (! $writeOff->isDraft()) {
            throw new RuntimeException('Write-off sudah di-submit.');
        }

        $product = Product::with('unit')->find($writeOff->product_id);
        if (! $product) {
            throw new RuntimeException('Produk tidak ditemukan.');
        }
        if (! $product->isStockTracked()) {
            throw new RuntimeException("Produk '{$product->name}' non-stock, tidak bisa di-write-off.");
        }

        $qtyInStock = $this->qtyInStockUnit($product, (float) $writeOff->qty, $writeOff->qty_unit_id);
        $storeId = (int) $writeOff->store_location_id;
        $reason = (string) $writeOff->reason;
        $note = $writeOff->note;

        return DB::transaction(function () use ($writeOff, $product, $qtyInStock, $storeId, $reason, $note, $userId) {
            $locked = StockWriteOff::query()->whereKey($writeOff->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->isDraft()) {
                throw new RuntimeException('Write-off sudah di-submit.');
            }

            $taken = $this->consumeFifo(
                (int) $product->id,
                $storeId,
                $qtyInStock,
                $reason,
                $userId,
                $note,
                $product->name
            );

            $totalCost = 0.0;
            foreach ($taken as $row) {
                $totalCost += $row['qty'] * $row['unit_cost'];
            }
            $unitCost = $qtyInStock > 0 ? $totalCost / $qtyInStock : 0.0;

            InventoryService::syncLegacyProductStock((int) $product->id, $storeId);

            $locked->fill([
                'unit_cost' => round($unitCost, 2),
                'total_cost' => round($totalCost, 2),
                'status' => StockWriteOff::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'submitted_by' => $userId,
                'register_session_id' => $locked->register_session_id
                    ?: $this->openRegisterSessionId($storeId, $userId),
            ]);
            $locked->save();

            return $locked->fresh(['product.unit', 'qtyUnit', 'user']);
        });
    }

    public function deleteDraft(StockWriteOff $writeOff): void
    {
        if (! $writeOff->isDraft()) {
            throw new RuntimeException('Write-off sudah di-submit, tidak bisa dihapus.');
        }
        $writeOff->delete();
    }

    /**
     * One document, many product lines.
     *
     * @param  array<int, array{product_id:int,qty:float,reason:string,note?:string|null,qty_unit_id?:int|null}>  $items
     * @return array<int, StockWriteOff>
     */
    public function createBatch(array $items, int $storeId, ?int $userId = null): array
    {
        if ($items === []) {
            throw new RuntimeException('Minimal satu baris write-off.');
        }

        $uid = $this->newBatchUid();

        return DB::transaction(function () use ($items, $storeId, $userId, $uid) {
            $rows = [];
            foreach ($items as $item) {
                $rows[] = $this->createDraft([
                    'product_id' => (int) $item['product_id'],
                    'qty' => (float) $item['qty'],
                    'qty_unit_id' => $item['qty_unit_id'] ?? null,
                    'reason' => $item['reason'],
                    'store_location_id' => $storeId,
                    'user_id' => $userId,
                    'note' => $item['note'] ?? null,
                    'batch_uid' => $uid,
                ]);
            }

            return $rows;
        });
    }

    /**
     * Replaces the lines of a draft document: rows with an id are updated,
     * rows without are added, and rows left out are removed.
     *
     * @param  array<int, array{id?:int|null,product_id:int,qty:float,reason:string,note?:string|null,qty_unit_id?:int|null}>  $items
     * @return array<int, StockWriteOff>
     */
    public function updateBatch(string $batchUid, array $items, ?int $userId = null): array
    {
        if ($items === []) {
            throw new RuntimeException('Minimal satu baris write-off.');
        }

        return DB::transaction(function () use ($batchUid, $items, $userId) {
            $existing = StockWriteOff::query()
                ->where('batch_uid', $batchUid)
                ->lockForUpdate()
                ->get();

            if ($existing->isEmpty()) {
                throw new RuntimeException('Write-off tidak ditemukan.');
            }
            if ($existing->contains(fn (StockWriteOff $row) => ! $row->isDraft())) {
                throw new RuntimeException('Write-off sudah di-submit, tidak bisa diubah.');
            }

            $storeId = (int) $existing->first()->store_location_id;
            $ownerId = $existing->first()->user_id ?? $userId;
            $keptIds = [];

            foreach ($items as $item) {
                $id = isset($item['id']) ? (int) $item['id'] : 0;
                $row = $id > 0 ? $existing->firstWhere('id', $id) : null;

                $payload = [
                    'product_id' => (int) $item['product_id'],
                    'qty' => (float) $item['qty'],
                    'qty_unit_id' => $item['qty_unit_id'] ?? null,
                    'reason' => $item['reason'],
                    'note' => $item['note'] ?? null,
                ];

                if ($row) {
                    $this->updateDraft($row, $payload);
                    $keptIds[] = (int) $row->id;
                    continue;
                }

                $created = $this->createDraft($payload + [
                    'store_location_id' => $storeId,
                    'user_id' => $ownerId,
                    'batch_uid' => $batchUid,
                ]);
                $keptIds[] = (int) $created->id;
            }

            StockWriteOff::query()
                ->where('batch_uid', $batchUid)
                ->whereNotIn('id', $keptIds)
                ->delete();

            return $this->batchRows($batchUid);
        });
    }

    /**
     * @return array<int, StockWriteOff>
     */
    public function submitBatch(string $batchUid, ?int $userId = null): array
    {
        return DB::transaction(function () use ($batchUid, $userId) {
            $rows = StockWriteOff::query()
                ->where('batch_uid', $batchUid)
                ->orderBy('id')
                ->get();

            if ($rows->isEmpty()) {
                throw new RuntimeException('Write-off tidak ditemukan.');
            }

            $drafts = $rows->filter(fn (StockWriteOff $row) => $row->isDraft());
            if ($drafts->isEmpty()) {
                throw new RuntimeException('Write-off sudah di-submit.');
            }

            foreach ($drafts as $row) {
                $this->submit($row, $userId);
            }

            return $this->batchRows($batchUid);
        });
    }

    public function deleteBatch(string $batchUid): void
    {
        DB::transaction(function () use ($batchUid) {
            $rows = StockWriteOff::query()
                ->where('batch_uid', $batchUid)
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                throw new RuntimeException('Write-off tidak ditemukan.');
            }
            if ($rows->contains(fn (StockWriteOff $row) => ! $row->isDraft())) {
                throw new RuntimeException('Write-off sudah di-submit, tidak bisa dihapus.');
            }

            StockWriteOff::query()->where('batch_uid', $batchUid)->delete();
        });
    }

    /**
     * @return array<int, StockWriteOff>
     */
    protected function batchRows(string $batchUid): array
    {
        return StockWriteOff::query()
            ->where('batch_uid', $batchUid)
            ->with(['product:id,sku,name,unit_id', 'product.unit:id,name', 'qtyUnit:id,name', 'user:id,name'])
            ->orderBy('id')
            ->get()
            ->all();
    }

    protected function newBatchUid(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Legacy one-shot create (consume immediately). Kept for callers that still need it.
     *
     * @param array{product_id:int,qty:float,reason:string,store_location_id:int,user_id?:int|null,note?:string|null} $p
     */
    public function record(array $p): StockWriteOff
    {
        $draft = $this->createDraft($p);

        return $this->submit($draft, $p['user_id'] ?? null);
    }

    /**
     * @return array{0:Product,1:float,2:string,3:int,4:?int,5:?string,6:?int}
     */
    protected function validateInput(array $p): array
    {
        $productId = (int) $p['product_id'];
        $storeId = (int) $p['store_location_id'];
        $qty = (float) $p['qty'];
        $reason = strtoupper((string) $p['reason']);
        $userId = $p['user_id'] ?? null;
        $note = $p['note'] ?? null;
        $qtyUnitId = array_key_exists('qty_unit_id', $p) && $p['qty_unit_id'] !== null && $p['qty_unit_id'] !== ''
            ? (int) $p['qty_unit_id']
            : null;

        if ($qty <= 1e-9) {
            throw new RuntimeException('Qty harus lebih dari 0.');
        }

        if (! in_array($reason, StockWriteOff::REASONS, true)) {
            throw new RuntimeException('Alasan write-off tidak valid.');
        }

        $product = Product::with('unit')->find($productId);
        if (! $product) {
            throw new RuntimeException('Produk tidak ditemukan.');
        }

        if (! $product->isStockTracked()) {
            throw new RuntimeException("Produk '{$product->name}' non-stock, tidak bisa di-write-off.");
        }

        $qtyUnitId = $this->normalizeQtyUnitId($product, $qtyUnitId);

        return [$product, $qty, $reason, $storeId, $userId, $note, $qtyUnitId];
    }

    /** Default to product stock unit when omitted / same id. */
    protected function normalizeQtyUnitId(Product $product, ?int $qtyUnitId): ?int
    {
        $stockUnitId = $product->unit_id ? (int) $product->unit_id : null;
        if ($qtyUnitId === null || $qtyUnitId === 0) {
            return $stockUnitId;
        }
        if ($stockUnitId !== null && $qtyUnitId === $stockUnitId) {
            return $stockUnitId;
        }
        if (! Unit::query()->whereKey($qtyUnitId)->exists()) {
            throw new RuntimeException('Satuan qty tidak valid.');
        }

        // Ensure conversion is possible (throws if incompatible).
        $this->qtyInStockUnit($product, 1.0, $qtyUnitId);

        return $qtyUnitId;
    }

    /** Convert entered qty into the product's stock unit for FIFO. */
    protected function qtyInStockUnit(Product $product, float $qty, ?int $qtyUnitId): float
    {
        $stockUnit = $product->unit;
        if (! $stockUnit) {
            return $qty;
        }

        $fromUnit = $qtyUnitId
            ? Unit::find($qtyUnitId)
            : $stockUnit;

        if (! $fromUnit) {
            throw new RuntimeException('Satuan qty tidak ditemukan.');
        }

        try {
            return UnitConversionService::convert($qty, $fromUnit, $stockUnit);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
    }

    /**
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
