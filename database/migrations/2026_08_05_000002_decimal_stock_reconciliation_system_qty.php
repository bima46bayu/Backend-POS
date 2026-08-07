<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('stock_reconciliation_items')
            && Schema::hasColumn('stock_reconciliation_items', 'system_qty')
        ) {
            Schema::table('stock_reconciliation_items', function (Blueprint $t) {
                $t->decimal('system_qty', 16, 4)->default(0)->change();
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('stock_reconciliation_items')
            && Schema::hasColumn('stock_reconciliation_items', 'system_qty')
        ) {
            Schema::table('stock_reconciliation_items', function (Blueprint $t) {
                $t->integer('system_qty')->default(0)->change();
            });
        }
    }
};
