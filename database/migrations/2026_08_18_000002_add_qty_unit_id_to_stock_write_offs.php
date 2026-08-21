<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('stock_write_offs')) {
            return;
        }

        Schema::table('stock_write_offs', function (Blueprint $t) {
            if (! Schema::hasColumn('stock_write_offs', 'qty_unit_id')) {
                // Unit the qty was entered in (e.g. g while product stock is kg).
                // Null = product stock unit.
                $t->unsignedBigInteger('qty_unit_id')->nullable()->after('qty')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_write_offs')) {
            return;
        }

        Schema::table('stock_write_offs', function (Blueprint $t) {
            if (Schema::hasColumn('stock_write_offs', 'qty_unit_id')) {
                $t->dropColumn('qty_unit_id');
            }
        });
    }
};
