<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('stock_write_offs')) {
            return;
        }

        Schema::table('stock_write_offs', function (Blueprint $t) {
            if (! Schema::hasColumn('stock_write_offs', 'batch_uid')) {
                // Groups the rows saved together in one "Catat Waste" into a single document.
                $t->string('batch_uid', 40)->nullable()->after('store_location_id')->index();
            }
        });

        // Existing rows each become their own single-line batch.
        DB::table('stock_write_offs')
            ->whereNull('batch_uid')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('stock_write_offs')
                        ->where('id', $row->id)
                        ->update(['batch_uid' => (string) Str::uuid()]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_write_offs')) {
            return;
        }

        Schema::table('stock_write_offs', function (Blueprint $t) {
            if (Schema::hasColumn('stock_write_offs', 'batch_uid')) {
                $t->dropColumn('batch_uid');
            }
        });
    }
};
