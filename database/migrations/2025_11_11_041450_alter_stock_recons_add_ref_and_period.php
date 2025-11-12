<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('stock_reconciliations', function (Blueprint $t) {
            if (!Schema::hasColumn('stock_reconciliations','reference_code')) {
                $t->string('reference_code', 80)->nullable()->after('name');
                $t->index('reference_code');
            }
            if (!Schema::hasColumn('stock_reconciliations','date_from')) {
                $t->date('date_from')->nullable()->after('status');
            }
            if (!Schema::hasColumn('stock_reconciliations','date_to')) {
                $t->date('date_to')->nullable()->after('date_from');
            }
            if (!Schema::hasColumn('stock_reconciliations','note')) {
                $t->text('note')->nullable()->after('date_to');
            }
        });
    }

    public function down(): void {
        Schema::table('stock_reconciliations', function (Blueprint $t) {
            if (Schema::hasColumn('stock_reconciliations','note')) $t->dropColumn('note');
            if (Schema::hasColumn('stock_reconciliations','date_to')) $t->dropColumn('date_to');
            if (Schema::hasColumn('stock_reconciliations','date_from')) $t->dropColumn('date_from');
            if (Schema::hasColumn('stock_reconciliations','reference_code')) $t->dropColumn('reference_code');
        });
    }
};
