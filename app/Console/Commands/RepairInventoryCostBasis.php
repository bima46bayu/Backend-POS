<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs inventory layers whose cost was recorded as the product's SELL price.
 *
 * Historically, opening stock created through the product form, the excel
 * import, and `inventory:bootstrap --use-product-price` wrote `products.price`
 * into `unit_landed_cost`. Because `inventory_consumptions.unit_cost` is copied
 * from the layer at sale time, COGS was inflated to revenue and reported margin
 * collapsed to ~0 for all of that stock.
 *
 * Detection is deliberately narrow: only layers where the recorded cost still
 * equals the current sell price (within a cent) AND the source is one of the
 * known opening-stock kinds. GR layers are never touched — they always carried a
 * real purchase price. A product legitimately bought at its sell price is
 * indistinguishable from the bug, so --require-cost-price (default) skips any
 * product with no known replacement cost rather than guessing.
 *
 * Defaults to a dry run. Nothing is written without --apply.
 */
class RepairInventoryCostBasis extends Command
{
    protected $signature = 'inventory:repair-cost-basis
        {--apply : Tulis perubahan (tanpa ini: dry-run saja)}
        {--store_id= : Batasi ke satu cabang}
        {--product_id= : Batasi ke satu produk}
        {--zero-unknown : Untuk produk tanpa cost_price, set biaya ke 0 (bukan dilewati)}
        {--include-consumed : Ikut perbaiki layer yang sudah habis terpakai}
        {--limit=0 : Batasi jumlah layer yang diproses (0 = semua)}';

    protected $description = 'Perbaiki inventory_layers yang biayanya salah tercatat sebagai harga jual';

    /** Opening-stock sources that used to take the sell price as cost. */
    private const SUSPECT_SOURCES = [
        'ADD_PRODUCT', 'IMPORT_OPEN', 'IMPORT_INIT', 'IMPORT_ADJUST', 'ADD', 'OPENING',
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('inventory_layers')) {
            $this->components->error('Tabel inventory_layers tidak ada.');

            return self::FAILURE;
        }

        $apply       = (bool) $this->option('apply');
        $zeroUnknown = (bool) $this->option('zero-unknown');
        $limit       = (int) $this->option('limit');
        $hasCostCol  = Schema::hasColumn('products', 'cost_price');

        if (! $hasCostCol && ! $zeroUnknown) {
            $this->components->error(
                'Kolom products.cost_price belum ada. Jalankan migrasi dulu, '.
                'atau pakai --zero-unknown bila memang ingin menormalkan biaya ke 0.'
            );

            return self::FAILURE;
        }

        $candidates = $this->candidates($hasCostCol, $limit);

        if ($candidates->isEmpty()) {
            $this->components->info('Tidak ada layer yang perlu diperbaiki.');

            return self::SUCCESS;
        }

        $this->components->info(
            ($apply ? 'APPLY' : 'DRY-RUN').' — '.$candidates->count().' layer terdeteksi memakai harga jual sebagai modal.'
        );

        $rows      = [];
        $fixed     = 0;
        $skipped   = 0;
        $cogsDelta = 0.0;

        foreach ($candidates as $layer) {
            $oldCost = (float) $layer->unit_landed_cost;
            $newCost = $layer->cost_price !== null ? (float) $layer->cost_price : null;

            if ($newCost === null) {
                if (! $zeroUnknown) {
                    $skipped++;
                    continue;
                }
                $newCost = 0.0;
            }

            if (abs($newCost - $oldCost) < 0.01) {
                $skipped++;
                continue;
            }

            // Consumed qty is what actually hit COGS.
            $consumedQty = (float) DB::table('inventory_consumptions')
                ->where('layer_id', $layer->id)
                ->sum('qty');

            $cogsDelta += ($oldCost - $newCost) * $consumedQty;

            if (count($rows) < 20) {
                $rows[] = [
                    $layer->id,
                    $layer->product_name ?? ('#'.$layer->product_id),
                    $layer->source_type ?? '(null)',
                    number_format($oldCost, 2),
                    number_format($newCost, 2),
                    rtrim(rtrim(number_format($consumedQty, 4, '.', ''), '0'), '.') ?: '0',
                ];
            }

            if ($apply) {
                $this->repairLayer((int) $layer->id, $newCost, (float) $layer->qty_initial);
            }

            $fixed++;
        }

        if ($rows) {
            $this->table(
                ['layer', 'product', 'source', 'cost lama', 'cost baru', 'qty terpakai'],
                $rows
            );
            if ($fixed > count($rows)) {
                $this->line('  … dan '.($fixed - count($rows)).' layer lainnya.');
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Layer diperbaiki', (string) $fixed);
        $this->components->twoColumnDetail('Layer dilewati', (string) $skipped);
        $this->components->twoColumnDetail(
            'Koreksi COGS (overstated)',
            number_format($cogsDelta, 2)
        );

        if ($skipped > 0 && ! $zeroUnknown) {
            $this->newLine();
            $this->components->warn(
                $skipped.' layer dilewati karena produknya belum punya cost_price. '.
                'Isi cost_price produk tersebut lalu jalankan ulang, atau pakai --zero-unknown.'
            );
        }

        $this->resyncDraftReconciliations($apply);

        if (! $apply) {
            $this->newLine();
            $this->components->warn('DRY-RUN: tidak ada perubahan ditulis. Tambahkan --apply untuk menerapkan.');
        }

        return self::SUCCESS;
    }

    /**
     * `stock_reconciliation_items.avg_cost` is a snapshot copied from the layers
     * when a draft is seeded, so drafts created before the repair still hold the
     * sell-price-derived average. Re-derive it from the (now corrected) layers.
     *
     * Only DRAFT reconciliations are touched. An APPLIED recon is a historical
     * record of what was actually posted; rewriting it would falsify the audit
     * trail even though the number in it is wrong.
     */
    private function resyncDraftReconciliations(bool $apply): void
    {
        if (! Schema::hasTable('stock_reconciliations') || ! Schema::hasTable('stock_reconciliation_items')) {
            return;
        }

        $qtyCol = Schema::hasColumn('inventory_layers', 'qty_remaining') ? 'qty_remaining' : 'qty';

        $drafts = DB::table('stock_reconciliations')
            ->whereRaw('UPPER(status) = ?', ['DRAFT'])
            ->get(['id', 'store_location_id', 'name']);

        if ($drafts->isEmpty()) {
            return;
        }

        $touched = 0;

        foreach ($drafts as $draft) {
            // Weighted average cost per product from the corrected layers.
            $live = DB::table('inventory_layers')
                ->select('product_id', DB::raw(
                    "CASE WHEN COALESCE(SUM({$qtyCol}),0) = 0 THEN 0
                          ELSE COALESCE(SUM({$qtyCol} * COALESCE(unit_landed_cost, unit_cost, unit_price)),0)
                               / NULLIF(SUM({$qtyCol}),0)
                     END AS avg_cost"
                ))
                ->where('store_location_id', $draft->store_location_id)
                ->where($qtyCol, '>', 0)
                ->groupBy('product_id')
                ->get()
                ->keyBy('product_id');

            $items = DB::table('stock_reconciliation_items')
                ->where('stock_reconciliation_id', $draft->id)
                ->get(['id', 'product_id', 'avg_cost']);

            foreach ($items as $item) {
                $new = (float) ($live[$item->product_id]->avg_cost ?? 0);
                if (abs($new - (float) $item->avg_cost) < 0.01) {
                    continue;
                }

                if ($apply) {
                    DB::table('stock_reconciliation_items')
                        ->where('id', $item->id)
                        ->update(['avg_cost' => $new, 'updated_at' => now()]);
                }
                $touched++;
            }
        }

        if ($touched > 0) {
            $this->newLine();
            $this->components->twoColumnDetail(
                'Item draft stock-opname disegarkan',
                (string) $touched
            );
            $this->line('  Recon berstatus APPLIED sengaja tidak diubah (jejak audit).');
        }
    }

    /**
     * Layers whose recorded cost still equals the product's current sell price.
     */
    private function candidates(bool $hasCostCol, int $limit)
    {
        $q = DB::table('inventory_layers as il')
            ->join('products as p', 'p.id', '=', 'il.product_id')
            ->select([
                'il.id', 'il.product_id', 'il.source_type', 'il.unit_landed_cost',
                'il.qty_initial', 'il.qty_remaining',
                'p.name as product_name', 'p.price',
            ])
            ->addSelect($hasCostCol ? 'p.cost_price' : DB::raw('NULL as cost_price'))
            // The signature of the bug: cost == sell price, and non-zero (a zero
            // cost is "unknown", not "wrongly set to price").
            ->whereRaw('ABS(il.unit_landed_cost - p.price) < 0.01')
            ->where('il.unit_landed_cost', '>', 0)
            // GR carried a genuine purchase price, so never rewrite it.
            ->where(function ($w) {
                $w->whereIn('il.source_type', self::SUSPECT_SOURCES)
                  ->orWhereNull('il.source_type');
            })
            ->orderBy('il.id');

        if ($storeId = $this->option('store_id')) {
            $q->where('il.store_location_id', (int) $storeId);
        }
        if ($productId = $this->option('product_id')) {
            $q->where('il.product_id', (int) $productId);
        }
        if (! $this->option('include-consumed')) {
            $q->where('il.qty_remaining', '>', 0);
        }
        if ($limit > 0) {
            $q->limit($limit);
        }

        return $q->get();
    }

    /**
     * Rewrite the layer and every downstream record that copied its cost, so
     * layers, consumptions and the ledger stay mutually consistent.
     */
    private function repairLayer(int $layerId, float $newCost, float $qtyInitial): void
    {
        DB::transaction(function () use ($layerId, $newCost, $qtyInitial) {
            $layerUpdate = [
                'unit_landed_cost' => $newCost,
                'updated_at'       => now(),
            ];
            if (Schema::hasColumn('inventory_layers', 'unit_cost')) {
                $layerUpdate['unit_cost'] = $newCost;
            }
            if (Schema::hasColumn('inventory_layers', 'estimated_cost')) {
                $layerUpdate['estimated_cost'] = $newCost * $qtyInitial;
            }

            DB::table('inventory_layers')->where('id', $layerId)->update($layerUpdate);

            // COGS actually reads from here.
            DB::table('inventory_consumptions')
                ->where('layer_id', $layerId)
                ->update(['unit_cost' => $newCost, 'updated_at' => now()]);

            if (! Schema::hasTable('stock_ledger')) {
                return;
            }

            $ledger = DB::table('stock_ledger')->where('layer_id', $layerId)->get(['id', 'qty']);
            foreach ($ledger as $row) {
                $update = ['unit_cost' => $newCost, 'updated_at' => now()];
                if (Schema::hasColumn('stock_ledger', 'subtotal_cost')) {
                    $update['subtotal_cost'] = $newCost * (float) $row->qty;
                }
                DB::table('stock_ledger')->where('id', $row->id)->update($update);
            }
        });
    }
}
