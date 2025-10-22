<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[AsCommand(
    name: 'inventory:bootstrap',
    description: 'Mirror products.stock -> inventory_layers (IMPORT_INIT) dengan unit cost dari product'
)]
class InventoryBootstrap extends Command
{
    protected $signature = 'inventory:bootstrap
        {--store_id= : ID cabang (opsional; NULL jika tidak pakai)}
        {--use-product-cost : Pakai kolom products.cost sebagai modal}
        {--use-product-price : Pakai kolom products.price sebagai modal}
        {--default-cost=0 : Cost per unit jika sumber modal kosong/tidak ada}
        {--dry-run : Simulasi; tidak menulis DB}
        {--force : Tetap tulis walau sudah ada IMPORT_INIT (hati-hati, bisa dobel)}';

    public function handle(): int
    {
        $storeId        = $this->option('store_id') !== null ? (int)$this->option('store_id') : null;
        $useProdCost    = (bool)$this->option('use-product-cost');
        $useProdPrice   = (bool)$this->option('use-product-price');
        $defaultCost    = (float)$this->option('default-cost');
        $dry            = (bool)$this->option('dry-run');
        $force          = (bool)$this->option('force');

        // deteksi kolom cost & price (biar aman kalau skema beda)
        $hasCost = false; $hasPrice = false;
        try { DB::statement('SELECT cost FROM products LIMIT 1');  $hasCost  = true; } catch (\Throwable $e) {}
        try { DB::statement('SELECT price FROM products LIMIT 1'); $hasPrice = true; } catch (\Throwable $e) {}

        if ($useProdCost && !$hasCost) {
            $this->components->warn('Kolom products.cost tidak ada — akan fallback ke --default-cost.');
        }
        if ($useProdPrice && !$hasPrice) {
            $this->components->warn('Kolom products.price tidak ada — akan fallback ke --default-cost.');
        }

        $this->components->info(
            "Mode modal: ".
            ($useProdCost ? 'product.cost' : ($useProdPrice ? 'product.price' : 'default-cost')).
            " | store=".($storeId ?? 'NULL').
            " | default-cost={$defaultCost}".
            ($dry ? " | DRY-RUN" : "").
            ($force ? " | FORCE" : "")
        );

        $total=0; $written=0; $skipped=0;

        DB::table('products')
            ->selectRaw('id, stock'.
                        ($hasCost ? ', cost' : ', 0 AS cost').
                        ($hasPrice ? ', price' : ', 0 AS price'))
            ->where('stock','>',0)
            ->orderBy('id')
            ->chunkById(500, function($rows) use ($storeId,$useProdCost,$useProdPrice,$defaultCost,$dry,$force,&$total,&$written,&$skipped) {

                foreach ($rows as $p) {
                    $total++;
                    $qty = (float)$p->stock;
                    if ($qty <= 0) { $skipped++; continue; }

                    if (!$force) {
                        $exists = DB::table('inventory_layers')
                            ->where('product_id', $p->id)
                            ->where('source_type', 'IMPORT_INIT')
                            ->when($storeId !== null, fn($q)=>$q->where('store_location_id',$storeId))
                            ->exists();
                        if ($exists) { $skipped++; continue; }
                    }

                    // Tentukan unit cost (modal)
                    $unit = $defaultCost;
                    if ($useProdCost && isset($p->cost) && $p->cost !== null) {
                        $unit = (float)$p->cost;
                    } elseif ($useProdPrice && isset($p->price) && $p->price !== null) {
                        $unit = (float)$p->price; // HATI2: secara akuntansi ini bukan cost, tapi sesuai kebutuhanmu
                    }

                    if ($dry) {
                        $this->line("[DRY] product_id={$p->id} qty={$qty} unit_cost={$unit}");
                        continue;
                    }

                    DB::table('inventory_layers')->insert([
                        'product_id'        => (int)$p->id,
                        'store_location_id' => $storeId,
                        'source_type'       => 'IMPORT_INIT',
                        'source_id'         => null,
                        'unit_price'        => null,
                        'unit_tax'          => 0,
                        'unit_other_cost'   => 0,
                        'unit_landed_cost'  => $unit,
                        'unit_cost'         => $unit,
                        'qty_initial'       => $qty,
                        'qty_remaining'     => $qty,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);

                    // Audit (opsional; tidak mengubah stok fisik)
                    DB::table('stock_logs')->insert([
                        'product_id'  => (int)$p->id,
                        'user_id'     => 1, // ganti sesuai kebutuhanmu
                        'change_type' => 'adjustment',
                        'quantity'    => 0,
                        'note'        => 'IMPORT_INIT (bootstrap modal awal)',
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);

                    $written++;
                }
            });

        $this->components->info("Selesai. total={$total}, dibuat={$written}, dilewati={$skipped}");
        if ($dry) $this->components->warn('DRY-RUN: tidak menulis DB.');
        if ($force && $written>0) $this->components->warn('FORCE: layer IMPORT_INIT bisa dobel. Hapus yang lama jika ingin overwrite bersih.');
        return self::SUCCESS;
    }
}
