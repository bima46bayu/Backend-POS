<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Populates `products.cost_price` from real purchase history.
 *
 * `cost_price` was introduced empty, so the repair command has no replacement
 * cost to write. Purchase history is the only place a genuine supplier price
 * could live, so we mine it rather than ask anyone to retype 1,600 products.
 *
 * The important subtlety: the sell-price bug contaminated purchase records too.
 * On the live data ~96% of both `purchase_items.unit_price` and GR layer costs
 * are byte-identical to `products.price`. Seeding those would launder the bug
 * into `cost_price` and make it permanent — and because the repair command
 * matches on "cost == price", it would then declare the data clean while every
 * margin stayed wrong.
 *
 * So a record is only trusted when it DIFFERS from the sell price. That yields
 * far fewer products, which is the honest answer: a cost nobody ever recorded
 * cannot be recovered by arithmetic. `--include-equal-to-price` exists for the
 * genuine at-cost reseller case, but it is never the default.
 *
 * Intended to run before `inventory:repair-cost-basis`. Dry run by default.
 */
class SeedProductCostPrice extends Command
{
    protected $signature = 'inventory:seed-cost-price
        {--apply : Tulis perubahan (tanpa ini: dry-run saja)}
        {--source=purchases : purchases|gr — sumber biaya}
        {--strategy=latest : latest|average — ambil yang terakhir atau rata-rata tertimbang}
        {--include-equal-to-price : (BAHAYA) Ikut pakai record yang biayanya = harga jual}
        {--overwrite : Timpa cost_price yang sudah terisi}
        {--limit=0 : Batasi jumlah produk (0 = semua)}';

    protected $description = 'Isi products.cost_price dari riwayat pembelian yang biayanya benar-benar berbeda dari harga jual';

    public function handle(): int
    {
        if (! Schema::hasColumn('products', 'cost_price')) {
            $this->components->error('Kolom products.cost_price belum ada. Jalankan migrasi dulu.');

            return self::FAILURE;
        }

        $apply        = (bool) $this->option('apply');
        $overwrite    = (bool) $this->option('overwrite');
        $strategy     = strtolower((string) $this->option('strategy'));
        $source       = strtolower((string) $this->option('source'));
        $includeEqual = (bool) $this->option('include-equal-to-price');
        $limit        = (int) $this->option('limit');

        if (! in_array($strategy, ['latest', 'average'], true)) {
            $this->components->error('--strategy hanya menerima: latest, average.');

            return self::FAILURE;
        }
        if (! in_array($source, ['purchases', 'gr'], true)) {
            $this->components->error('--source hanya menerima: purchases, gr.');

            return self::FAILURE;
        }
        if ($source === 'purchases' && ! Schema::hasTable('purchase_items')) {
            $this->components->error('Tabel purchase_items tidak ada. Coba --source=gr.');

            return self::FAILURE;
        }

        $costs = $this->costs($source, $strategy, $includeEqual, $limit);

        if ($costs->isEmpty()) {
            $this->components->info('Tidak ada riwayat biaya yang bisa dipercaya.');

            return self::SUCCESS;
        }

        $this->components->info(
            ($apply ? 'APPLY' : 'DRY-RUN')." — sumber: {$source} ({$strategy}), ".
            $costs->count().' produk punya riwayat biaya'.
            ($includeEqual ? ' (TERMASUK yang = harga jual)' : ' yang berbeda dari harga jual').'.'
        );

        if ($includeEqual) {
            $this->components->warn(
                'Anda memakai --include-equal-to-price. Record dengan biaya = harga jual '.
                'kemungkinan besar hasil bug lama, dan menyalinnya ke cost_price akan '.
                'membuat kesalahan itu permanen serta tidak lagi terdeteksi.'
            );
        }

        $rows = [];
        $set = 0;
        $skipped = 0;
        $suspicious = 0;

        foreach ($costs as $row) {
            $cost = (float) $row->cost;
            if ($cost <= 0) {
                $skipped++;
                continue;
            }

            $product = DB::table('products')->where('id', $row->product_id)->first(['id', 'name', 'price', 'cost_price']);
            if (! $product) {
                $skipped++;
                continue;
            }

            if ($product->cost_price !== null && ! $overwrite) {
                $skipped++;
                continue;
            }

            // Cost above the sell price is possible but usually signals bad data,
            // so surface it rather than writing it silently.
            $flag = $cost > (float) $product->price ? ' (!)' : '';
            if ($flag !== '') {
                $suspicious++;
            }

            if (count($rows) < 20) {
                $rows[] = [
                    $product->id,
                    mb_strimwidth((string) $product->name, 0, 34, '…'),
                    number_format((float) $product->price, 2),
                    number_format($cost, 2).$flag,
                ];
            }

            if ($apply) {
                DB::table('products')->where('id', $product->id)->update([
                    'cost_price' => $cost,
                    'updated_at' => now(),
                ]);
            }

            $set++;
        }

        if ($rows) {
            $this->table(['product', 'nama', 'harga jual', 'cost dari GR'], $rows);
            if ($set > count($rows)) {
                $this->line('  … dan '.($set - count($rows)).' produk lainnya.');
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('cost_price diisi', (string) $set);
        $this->components->twoColumnDetail('Dilewati', (string) $skipped);

        if ($suspicious > 0) {
            $this->components->twoColumnDetail('Cost > harga jual (!)', (string) $suspicious);
        }

        $remaining = DB::table('products')->whereNull('cost_price')->count();
        $this->components->twoColumnDetail(
            'Produk masih tanpa cost_price'.($apply ? '' : ' (setelah apply)'),
            (string) ($apply ? $remaining : max(0, $remaining - $set))
        );

        if (! $apply) {
            $this->newLine();
            $this->components->warn('DRY-RUN: tidak ada perubahan ditulis. Tambahkan --apply untuk menerapkan.');
        } else {
            $this->newLine();
            $this->components->info('Lanjutkan dengan: php artisan inventory:repair-cost-basis (dry-run dulu).');
        }

        return self::SUCCESS;
    }

    /**
     * Trustworthy per-product costs from the chosen source.
     *
     * `$includeEqual = false` (the default) drops any record whose cost equals
     * the sell price, because that is the fingerprint of the original bug rather
     * than evidence of a real purchase price.
     */
    private function costs(string $source, string $strategy, bool $includeEqual, int $limit)
    {
        // GREATEST/MAX-of-two isn't portable (MySQL has GREATEST, SQLite has
        // MAX(a,b) as a scalar), so express the "at least 1 unit" floor with a
        // CASE, which both understand.
        $units = 'CASE WHEN COALESCE(pi.qty_received, pi.qty_order, 0) > 0 THEN COALESCE(pi.qty_received, pi.qty_order) ELSE 1 END';

        [$table, $alias, $costExpr, $qtyCol] = $source === 'purchases'
            // Net supplier price per unit: discount and tax are per-line, so spread them.
            ? [
                'purchase_items',
                'pi',
                "(pi.unit_price - (COALESCE(pi.discount,0) / ({$units})) + (COALESCE(pi.tax,0) / ({$units})))",
                'COALESCE(pi.qty_received, pi.qty_order)',
            ]
            : ['inventory_layers', 'il', 'il.unit_landed_cost', 'il.qty_initial'];

        $q = DB::table("{$table} as {$alias}")
            ->join('products as p', 'p.id', '=', "{$alias}.product_id")
            ->whereRaw("{$costExpr} > 0");

        if ($source === 'gr') {
            $q->where("{$alias}.source_type", 'GR');
        }

        if (! $includeEqual) {
            $q->whereRaw("ABS({$costExpr} - p.price) >= 0.01");
        }

        if ($strategy === 'average') {
            $q->selectRaw(
                "{$alias}.product_id, SUM({$qtyCol} * {$costExpr}) / NULLIF(SUM({$qtyCol}),0) AS cost"
            )
                ->whereRaw("{$qtyCol} > 0")
                ->groupBy("{$alias}.product_id")
                ->orderBy("{$alias}.product_id");
        } else {
            // Most recent record per product.
            $q->selectRaw("{$alias}.product_id, {$costExpr} AS cost")
                ->whereIn("{$alias}.id", function ($sub) use ($table, $alias, $source, $costExpr, $includeEqual) {
                    $sub->selectRaw("MAX({$alias}2.id)")
                        ->from("{$table} as {$alias}2")
                        ->join('products as p2', 'p2.id', '=', "{$alias}2.product_id")
                        ->whereRaw(str_replace("{$alias}.", "{$alias}2.", $costExpr).' > 0')
                        ->groupBy("{$alias}2.product_id");

                    if ($source === 'gr') {
                        $sub->where("{$alias}2.source_type", 'GR');
                    }
                    if (! $includeEqual) {
                        // Parenthesised via ABS() so the condition can't bind
                        // loosely against the surrounding ANDs.
                        $sub->whereRaw(
                            'ABS('.str_replace("{$alias}.", "{$alias}2.", $costExpr).' - p2.price) >= 0.01'
                        );
                    }
                })
                ->orderBy("{$alias}.product_id");
        }

        if ($limit > 0) {
            $q->limit($limit);
        }

        return $q->get();
    }
}
