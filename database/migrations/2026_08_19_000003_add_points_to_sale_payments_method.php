<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Member Store redeem writes sale_payments.method = POINTS.
 * The original enum only allowed cash/card/ewallet/transfer/QRIS.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('sale_payments')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE sale_payments MODIFY COLUMN method ENUM('cash','card','ewallet','transfer','QRIS','POINTS') NOT NULL"
            );

            return;
        }

        // SQLite (tests) implements enum as varchar + CHECK constraint, so the
        // original list would reject POINTS and the redeem path could never be
        // covered by a test. Rebuild the column as a plain string to drop it.
        if ($driver === 'sqlite') {
            Schema::table('sale_payments', function (Blueprint $table) {
                $table->string('method', 20)->default('cash')->change();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sale_payments')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::table('sale_payments')->where('method', 'POINTS')->update(['method' => 'ewallet']);
            DB::statement(
                "ALTER TABLE sale_payments MODIFY COLUMN method ENUM('cash','card','ewallet','transfer','QRIS') NOT NULL"
            );
        }
    }
};
