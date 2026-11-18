<?php

namespace App\Services;

use App\Models\StoreLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes a store and the operational data that belongs to it.
 *
 * Staff accounts are unassigned, not deleted. Child stores are not touched;
 * the caller must refuse a store that still has branches.
 */
class StoreLocationPurger
{
    public function purge(StoreLocation $store): void
    {
        DB::transaction(function () use ($store) {
            $storeId = (int) $store->id;

            $productIds = $this->ids('products', 'store_location_id', $storeId);
            $saleIds = $this->ids('sales', 'store_location_id', $storeId);
            $purchaseIds = $this->ids('purchases', 'store_location_id', $storeId);
            $memberIds = $this->ids('members', 'store_location_id', $storeId);
            $rewardIds = $this->ids('loyalty_rewards', 'store_location_id', $storeId);
            $groupIds = $this->ids('product_option_groups', 'store_location_id', $storeId);
            $reconIds = $this->ids('stock_reconciliations', 'store_location_id', $storeId);
            $registerIds = $this->ids('register_sessions', 'store_location_id', $storeId);
            $paymentIds = $this->ids('payment_requests', 'store_location_id', $storeId);
            $categoryIds = $this->ids('categories', 'store_location_id', $storeId);
            $subIds = $this->ids('sub_categories', 'store_location_id', $storeId);

            $this->deleteAny('reward_reservations', [
                'store_location_id' => [$storeId],
                'member_id' => $memberIds,
                'loyalty_reward_id' => $rewardIds,
                'sale_id' => $saleIds,
            ]);
            $this->deleteAny('member_point_transactions', [
                'store_location_id' => [$storeId],
                'member_id' => $memberIds,
                'loyalty_reward_id' => $rewardIds,
                'sale_id' => $saleIds,
            ]);

            $this->deleteWhereIn('sale_items', 'sale_id', $saleIds);
            $this->deleteWhereIn('sale_items', 'product_id', $productIds);
            $this->deleteWhereIn('sale_payments', 'sale_id', $saleIds);
            $this->deleteWhereIn('sales', 'id', $saleIds);

            $this->deleteWhereIn('purchases', 'id', $purchaseIds);

            foreach ([
                'inventory_layers',
                'inventory_consumptions',
                'stock_ledger',
                'stock_write_offs',
                'stock_logs',
                'cost_adjustments',
            ] as $table) {
                $this->deleteWhereIn($table, 'store_location_id', [$storeId]);
                $this->deleteWhereIn($table, 'product_id', $productIds);
                if ($table === 'inventory_consumptions') {
                    $this->deleteWhereIn($table, 'sale_id', $saleIds);
                }
            }

            $this->deleteWhereIn('stock_reconciliation_items', 'stock_reconciliation_id', $reconIds);
            $this->deleteWhereIn('stock_reconciliation_items', 'product_id', $productIds);
            $this->deleteWhereIn('stock_reconciliations', 'id', $reconIds);

            $this->deleteWhereIn('product_product_option_group', 'product_id', $productIds);
            $this->deleteWhereIn('product_product_option_group', 'product_option_group_id', $groupIds);
            $this->deleteWhereIn('product_option_values', 'product_option_group_id', $groupIds);
            $this->deleteWhereIn('product_option_groups', 'id', $groupIds);

            $this->deleteWhereIn('loyalty_rewards', 'id', $rewardIds);
            $this->nullWhereIn('loyalty_rewards', 'product_id', $productIds);

            $this->deleteWhereIn('products', 'id', $productIds);

            $extraSubs = $this->idsWhereIn('sub_categories', 'category_id', $categoryIds);
            $this->deleteWhereIn('sub_categories', 'id', array_values(array_unique([...$subIds, ...$extraSubs])));
            $this->deleteWhereIn('categories', 'id', $categoryIds);

            foreach (['additional_charges', 'discounts', 'pos_cart_drafts', 'activity_logs'] as $table) {
                $this->deleteWhereIn($table, 'store_location_id', [$storeId]);
            }

            foreach (['payment_request_details', 'payment_request_balances', 'payment_request_items'] as $table) {
                $this->deleteWhereIn($table, 'payment_request_id', $paymentIds);
            }
            $this->deleteWhereIn('payment_requests', 'id', $paymentIds);

            $this->deleteWhereIn('register_sessions', 'id', $registerIds);

            $this->deleteWhereIn('promotion_dismissals', 'member_id', $memberIds);
            $this->deleteWhereIn('member_accounts', 'member_id', $memberIds);
            $this->deleteWhereIn('members', 'id', $memberIds);

            if (Schema::hasTable('users') && Schema::hasColumn('users', 'store_location_id')) {
                DB::table('users')->where('store_location_id', $storeId)->update([
                    'store_location_id' => null,
                ]);
            }

            $logo = $store->logo_url;
            $store->delete();

            if (is_string($logo) && $logo !== '') {
                $path = public_path(ltrim($logo, '/'));
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        });
    }

    /**
     * @return int[]
     */
    private function ids(string $table, string $column, int $storeId): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)
            ->where($column, $storeId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  int[]  $ids
     * @return int[]
     */
    private function idsWhereIn(string $table, string $column, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id') || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        $found = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $found = array_merge(
                $found,
                DB::table($table)->whereIn($column, $chunk)->pluck('id')->map(fn ($id) => (int) $id)->all()
            );
        }

        return $found;
    }

    /**
     * @param  array<string, int[]>  $columns
     */
    private function deleteAny(string $table, array $columns): void
    {
        foreach ($columns as $column => $ids) {
            $this->deleteWhereIn($table, $column, $ids);
        }
    }

    /**
     * @param  int[]  $ids
     */
    private function deleteWhereIn(string $table, string $column, array $ids): void
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table($table)->whereIn($column, $chunk)->delete();
        }
    }

    /**
     * @param  int[]  $ids
     */
    private function nullWhereIn(string $table, string $column, array $ids): void
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        if (! $this->columnIsNullable($table, $column)) {
            return;
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table($table)->whereIn($column, $chunk)->update([$column => null]);
        }
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        $match = collect(Schema::getColumns($table))->firstWhere('name', $column);

        return (bool) ($match['nullable'] ?? false);
    }
}
