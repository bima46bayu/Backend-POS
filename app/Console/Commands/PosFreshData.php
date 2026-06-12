<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[AsCommand(
    name: 'pos:fresh-data',
    description: 'Wipe POS transactional/catalog data for v2.0 testing (keeps users, stores, units)'
)]
class PosFreshData extends Command
{
    protected $signature = 'pos:fresh-data
        {--dry-run : Show tables that would be truncated}
        {--yes : Skip confirmation prompt}
        {--force : Required when APP_ENV=production}';

    /** Child tables first, then parents. */
    private array $truncateTables = [
        'register_session_carts',
        'register_sessions',
        'pos_cart_drafts',
        'inventory_consumptions',
        'stock_ledger',
        'stock_logs',
        'stock_reconciliation_items',
        'stock_reconciliations',
        'inventory_layers',
        'goods_receipt_items',
        'goods_receipts',
        'purchase_items',
        'purchases',
        'sale_payments',
        'sale_items',
        'sales',
        'products',
        'sub_categories',
        'categories',
        'suppliers',
        'discounts',
        'additional_charges',
        'payment_request_details',
        'payment_request_balances',
        'payment_requests',
        'payees',
        'bank_accounts',
        'coas',
        'personal_access_tokens',
    ];

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->components->error('Production requires --force. Aborting.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $existing = array_values(array_filter(
            $this->truncateTables,
            fn (string $t) => Schema::hasTable($t)
        ));

        $this->components->info('Keeps: users, store_locations, units, migrations');
        $this->components->info('Truncates '.count($existing).' table(s)');

        if ($dryRun) {
            foreach ($existing as $t) {
                $count = DB::table($t)->count();
                $this->line("  [DRY] {$t} ({$count} rows)");
            }

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('This permanently deletes products, sales, inventory, PO/GR, etc. Continue?', true)) {
            $this->components->warn('Cancelled.');

            return self::SUCCESS;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($existing as $table) {
                DB::table($table)->truncate();
                $this->line("  truncated {$table}");
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->components->info('Done. Log in with existing users; import products per cabang to begin.');

        return self::SUCCESS;
    }
}
