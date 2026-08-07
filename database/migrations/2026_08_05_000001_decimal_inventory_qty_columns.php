<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recipe ingredients often use fractional stock units (0.05 L, 0.25 kg).
 * Integer qty columns truncated those to 0 and broke stock tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_layers')) {
            Schema::table('inventory_layers', function (Blueprint $t) {
                if (Schema::hasColumn('inventory_layers', 'qty_initial')) {
                    $t->decimal('qty_initial', 16, 4)->default(0)->change();
                }
                if (Schema::hasColumn('inventory_layers', 'qty_remaining')) {
                    $t->decimal('qty_remaining', 16, 4)->default(0)->change();
                }
            });
        }

        if (Schema::hasTable('inventory_consumptions') && Schema::hasColumn('inventory_consumptions', 'qty')) {
            Schema::table('inventory_consumptions', function (Blueprint $t) {
                $t->decimal('qty', 16, 4)->change();
            });
        }

        if (Schema::hasTable('stock_ledger') && Schema::hasColumn('stock_ledger', 'qty')) {
            Schema::table('stock_ledger', function (Blueprint $t) {
                $t->decimal('qty', 16, 4)->change();
            });
        }

        if (Schema::hasTable('stock_write_offs') && Schema::hasColumn('stock_write_offs', 'qty')) {
            Schema::table('stock_write_offs', function (Blueprint $t) {
                $t->decimal('qty', 16, 4)->change();
            });
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'stock')) {
            Schema::table('products', function (Blueprint $t) {
                $t->decimal('stock', 16, 4)->default(0)->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_layers')) {
            Schema::table('inventory_layers', function (Blueprint $t) {
                if (Schema::hasColumn('inventory_layers', 'qty_initial')) {
                    $t->unsignedInteger('qty_initial')->default(0)->change();
                }
                if (Schema::hasColumn('inventory_layers', 'qty_remaining')) {
                    $t->unsignedInteger('qty_remaining')->default(0)->change();
                }
            });
        }

        if (Schema::hasTable('inventory_consumptions') && Schema::hasColumn('inventory_consumptions', 'qty')) {
            Schema::table('inventory_consumptions', function (Blueprint $t) {
                $t->unsignedInteger('qty')->change();
            });
        }

        if (Schema::hasTable('stock_ledger') && Schema::hasColumn('stock_ledger', 'qty')) {
            Schema::table('stock_ledger', function (Blueprint $t) {
                $t->unsignedInteger('qty')->change();
            });
        }

        if (Schema::hasTable('stock_write_offs') && Schema::hasColumn('stock_write_offs', 'qty')) {
            Schema::table('stock_write_offs', function (Blueprint $t) {
                $t->unsignedInteger('qty')->change();
            });
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'stock')) {
            Schema::table('products', function (Blueprint $t) {
                $t->integer('stock')->default(0)->change();
            });
        }
    }
};
