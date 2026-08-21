<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Members + Member Store catalog are company-wide (one card, one prize list).
 * store_location_id stays as "registered / created at" only.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('members')) {
            DB::table('members')->where('phone', '')->update(['phone' => null]);
            $this->uniquifyColumn('members', 'code');
            $this->uniquifyColumn('members', 'phone');

            $this->dropIndexQuietly('members', 'members_store_location_id_code_unique');
            $this->dropIndexQuietly('members', 'members_store_location_id_phone_unique');

            Schema::table('members', function (Blueprint $table) {
                $table->unique('code');
                $table->unique('phone');
            });
        }

        if (Schema::hasTable('loyalty_rewards')) {
            $keep = DB::table('loyalty_rewards')
                ->whereNotNull('product_id')
                ->orderBy('id')
                ->get()
                ->unique('product_id')
                ->pluck('id');

            if ($keep->isNotEmpty()) {
                DB::table('loyalty_rewards')
                    ->whereNotNull('product_id')
                    ->whereNotIn('id', $keep)
                    ->delete();
            }

            $this->dropIndexQuietly('loyalty_rewards', 'loyalty_rewards_store_product_unique');

            Schema::table('loyalty_rewards', function (Blueprint $table) {
                $table->unique('product_id', 'loyalty_rewards_product_id_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('members')) {
            $this->dropIndexQuietly('members', 'members_code_unique');
            $this->dropIndexQuietly('members', 'members_phone_unique');
            Schema::table('members', function (Blueprint $table) {
                $table->unique(['store_location_id', 'code']);
                $table->unique(['store_location_id', 'phone']);
            });
        }

        if (Schema::hasTable('loyalty_rewards')) {
            $this->dropIndexQuietly('loyalty_rewards', 'loyalty_rewards_product_id_unique');
            Schema::table('loyalty_rewards', function (Blueprint $table) {
                $table->unique(['store_location_id', 'product_id'], 'loyalty_rewards_store_product_unique');
            });
        }
    }

    private function uniquifyColumn(string $table, string $column): void
    {
        $dupes = DB::table($table)
            ->select($column)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($column);

        foreach ($dupes as $value) {
            $rows = DB::table($table)->where($column, $value)->orderBy('id')->get(['id']);
            $skip = true;
            foreach ($rows as $row) {
                if ($skip) {
                    $skip = false;
                    continue;
                }
                DB::table($table)->where('id', $row->id)->update([
                    $column => $value . '-' . $row->id,
                ]);
            }
        }
    }

    private function dropIndexQuietly(string $table, string $index): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($index) {
                $blueprint->dropUnique($index);
            });
        } catch (\Throwable $e) {
            // already dropped / never existed
        }
    }
};
