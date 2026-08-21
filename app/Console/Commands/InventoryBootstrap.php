<?php

namespace App\Console\Commands;

use App\Services\InventoryService;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[AsCommand(
    name: 'inventory:bootstrap',
    description: 'Mirror products.stock -> inventory_layers (IMPORT_INIT) dengan unit cost dari product'
)]
class InventoryBootstrap extends Command
{
    protected $signature = 'inventory:bootstrap
        {--store_id= : ID cabang (opsional; NULL jika tidak pakai)}
        {--use-product-cost : Pakai kolom products.cost_price sebagai modal}
        {--use-product-price : (BAHAYA) Pakai harga JUAL sebagai modal — butuh --i-know-this-breaks-cogs}
        {--i-know-this-breaks-cogs : Konfirmasi eksplisit untuk --use-product-price}
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

        // products.price is the SELL price. Using it as inventory cost makes
        // COGS equal revenue and reports ~0 margin, so it now requires explicit
        // opt-in rather than being a casual flag.
        if ($useProdPrice && ! $this->option('i-know-this-breaks-cogs')) {
            $this->components->error(
                '--use-product-price memakai HARGA JUAL sebagai modal. Ini membuat COGS = revenue '.
                'dan margin jadi ~0. Gunakan --use-product-cost (products.cost_price), atau ulangi '.
                'dengan --i-know-this-breaks-cogs bila memang disengaja.'
            );

            return self::FAILURE;
        }

        // deteksi kolom cost & price (biar aman kalau skema beda)
        $hasCost  = Schema::hasColumn('products', 'cost_price');
        $hasPrice = Schema::hasColumn('products', 'price');

        if ($useProdCost && !$hasCost) {
            $this->components->warn('Kolom products.cost_price tidak ada — akan fallback ke --default-cost.');
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
                        ($hasCost ? ', cost_price AS cost' : ', 0 AS cost').
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
                        // Harga jual, bukan modal. Hanya sampai sini kalau user
                        // sudah lewat --i-know-this-breaks-cogs di atas.
                        $unit = (float)$p->price;
                    }

                    if ($dry) {
                        $this->line("[DRY] product_id={$p->id} qty={$qty} unit_cost={$unit}");
                        continue;
                    }

                    // Route through the one inbound helper so this command can't
                    // drift from GR/import/opname again.
                    app(InventoryService::class)->addInboundLayer([
                        'product_id'        => (int)$p->id,
                        'store_location_id' => $storeId,
                        'qty'               => $qty,
                        'unit_cost'         => $unit,
                        'source_type'       => 'IMPORT_INIT',
                        'note'              => 'IMPORT_INIT (bootstrap modal awal)',
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
