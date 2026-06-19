<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_reconciliations', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_reconciliations', 'total_items')) {
                $table->unsignedInteger('total_items')->default(0)->after('status');
            }
            if (! Schema::hasColumn('stock_reconciliations', 'total_value')) {
                $table->decimal('total_value', 18, 2)->default(0)->after('total_items');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_reconciliations', function (Blueprint $table) {
            if (Schema::hasColumn('stock_reconciliations', 'total_value')) {
                $table->dropColumn('total_value');
            }
            if (Schema::hasColumn('stock_reconciliations', 'total_items')) {
                $table->dropColumn('total_items');
            }
        });
    }
};
