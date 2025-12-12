<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'store_location_id')) {
                $table->unsignedBigInteger('store_location_id')
                    ->nullable()
                    ->after('cashier_id');

                // index gabungan biar query filter + tanggal makin kenceng
                $table->index(
                    ['store_location_id', 'created_at'],
                    'sales_store_created_idx'
                );
            }
        });

        // === BACKFILL DATA LAMA DARI users.store_location_id ===
        // asumsi: kolom store_location_id juga ada di tabel users
        if (
            Schema::hasColumn('sales', 'store_location_id') &&
            Schema::hasTable('users') &&
            Schema::hasColumn('users', 'store_location_id')
        ) {
            // MySQL / MariaDB
            DB::statement("
                UPDATE sales s
                JOIN users u ON u.id = s.cashier_id
                SET s.store_location_id = u.store_location_id
                WHERE s.store_location_id IS NULL
                  AND u.store_location_id IS NOT NULL
            ");
        }

        // (opsional) tambah foreign key
        if (Schema::hasTable('store_locations')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->foreign('store_location_id')
                    ->references('id')
                    ->on('store_locations')
                    ->onDelete('restrict');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'store_location_id')) {
                // drop FK kalau ada
                try {
                    $table->dropForeign(['store_location_id']);
                } catch (\Throwable $e) {
                    // silent fail kalau nama FK beda
                }

                $table->dropIndex('sales_store_created_idx');
                $table->dropColumn('store_location_id');
            }
        });
    }
};
