<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product options can bump recipe ingredient usage up or down.
 *
 * - Group points at one stock product (e.g. Ice).
 * - Each choice carries a signed qty_delta in that ingredient's stock unit
 *   (More Ice +5, No Ice -10). At sale time: max(0, recipe_qty + delta).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_option_groups', function (Blueprint $table) {
            if (! Schema::hasColumn('product_option_groups', 'ingredient_product_id')) {
                $table->foreignId('ingredient_product_id')
                    ->nullable()
                    ->after('store_location_id')
                    ->constrained('products')
                    ->nullOnDelete();
            }
        });

        Schema::table('product_option_values', function (Blueprint $table) {
            if (! Schema::hasColumn('product_option_values', 'qty_delta')) {
                $table->decimal('qty_delta', 12, 4)
                    ->default(0)
                    ->after('price_delta');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_option_values', function (Blueprint $table) {
            if (Schema::hasColumn('product_option_values', 'qty_delta')) {
                $table->dropColumn('qty_delta');
            }
        });

        Schema::table('product_option_groups', function (Blueprint $table) {
            if (Schema::hasColumn('product_option_groups', 'ingredient_product_id')) {
                $table->dropConstrainedForeignId('ingredient_product_id');
            }
        });
    }
};
