<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member Store prizes are catalog products: redeem deducts points and stock.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('loyalty_rewards')) {
            return;
        }

        Schema::table('loyalty_rewards', function (Blueprint $table) {
            if (! Schema::hasColumn('loyalty_rewards', 'product_id')) {
                $table->foreignId('product_id')
                    ->nullable()
                    ->after('store_location_id')
                    ->constrained('products')
                    ->restrictOnDelete();
            }
        });

        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->unique(['store_location_id', 'product_id'], 'loyalty_rewards_store_product_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('loyalty_rewards')) {
            return;
        }

        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->dropUnique('loyalty_rewards_store_product_unique');
            if (Schema::hasColumn('loyalty_rewards', 'product_id')) {
                $table->dropConstrainedForeignId('product_id');
            }
        });
    }
};
