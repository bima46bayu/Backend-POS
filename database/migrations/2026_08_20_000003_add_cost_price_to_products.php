<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `products` only had `price` (the SELLING price). Inventory helpers that needed
 * a cost had nowhere to read one, so they fell back to `price` and wrote the
 * sell price into inventory_layers.unit_landed_cost. That flows into
 * inventory_consumptions.unit_cost on every sale, which inflated COGS up to
 * revenue and reported ~0 margin on all opening-stock inventory.
 *
 * `cost_price` is nullable on purpose: NULL means "cost unknown" (valuation 0)
 * rather than silently pretending the sell price is the cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'cost_price')) {
                $table->decimal('cost_price', 12, 2)->nullable()->after('price');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'cost_price')) {
                $table->dropColumn('cost_price');
            }
        });
    }
};
