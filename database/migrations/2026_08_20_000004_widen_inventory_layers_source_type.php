<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `inventory_layers.source_type` was created as
 * enum('ADD_PRODUCT','GR','ADJUSTMENT_IN','IMPORT_INIT') but the codebase writes
 * far more values than that: IMPORT_OPEN and IMPORT_ADJUST (excel import),
 * ADJUST_IN / RECON (stock opname), VOID (sales returns). On MySQL an
 * out-of-range enum write fails, and because the inbound helper retries without
 * the column, the layer silently lands with source_type = NULL. NULL layers are
 * then invisible to opening-stock reporting, which filters on those very names.
 *
 * Widening to a plain string removes the whole class of problem: the column is
 * descriptive metadata, not something worth an enum's rigidity. SQLite already
 * treats it as varchar (an earlier ->change() dropped the CHECK constraint),
 * which is exactly why tests never caught this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_layers') || ! Schema::hasColumn('inventory_layers', 'source_type')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            // Avoid ->change(), which needs doctrine/dbal for enum columns.
            DB::statement('ALTER TABLE `inventory_layers` MODIFY `source_type` VARCHAR(32) NULL');
        } else {
            Schema::table('inventory_layers', function (Blueprint $table) {
                $table->string('source_type', 32)->nullable()->change();
            });
        }

        // Recover rows that were already written as NULL by the failed-enum
        // retry path, using the note text the callers stamped on them.
        DB::table('inventory_layers')
            ->whereNull('source_type')
            ->where('note', 'like', '%import excel%')
            ->update(['source_type' => 'IMPORT_OPEN']);

        DB::table('inventory_layers')
            ->whereNull('source_type')
            ->where('note', 'like', '%Stok awal%')
            ->update(['source_type' => 'ADD_PRODUCT']);
    }

    public function down(): void
    {
        // Deliberately not restoring the enum: it was too narrow for the values
        // the application writes, so reverting would reintroduce silent NULLs.
    }
};
