// database/migrations/2025_11_04_000001_add_store_location_to_products.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1) Tambah kolom + index bila belum ada (tanpa FK dulu)
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'store_location_id')) {
                $table->unsignedBigInteger('store_location_id')->nullable()->after('sub_category_id');
                $table->index('store_location_id', 'products_store_location_id_index');
            }

            if (!Schema::hasColumn('products', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('image_url');
                $table->index('created_by', 'products_created_by_index');
            }
        });

        // 2) Drop FK aneh bernama `1` kalau ada (beberapa server bikin nama FK buruk)
        $weirdFk = DB::selectOne("
            SELECT CONSTRAINT_NAME
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'products'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
              AND CONSTRAINT_NAME = '1'
        ");
        if ($weirdFk) {
            DB::statement('ALTER TABLE `products` DROP FOREIGN KEY `1`');
        }

        // Helper cek FK by name
        $fkExists = function (string $name): bool {
            return (bool) DB::selectOne("
                SELECT 1
                FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'products'
                  AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                  AND CONSTRAINT_NAME = ?
            ", [$name]);
        };

        // 3) Tambah FK dengan NAMA yang jelas (hanya jika belum ada)
        if (Schema::hasColumn('products', 'store_location_id') && ! $fkExists('products_store_location_id_fk')) {
            DB::statement("
                ALTER TABLE `products`
                ADD CONSTRAINT `products_store_location_id_fk`
                FOREIGN KEY (`store_location_id`) REFERENCES `store_locations`(`id`)
                ON DELETE SET NULL
            ");
        }

        if (Schema::hasColumn('products', 'created_by') && ! $fkExists('products_created_by_fk')) {
            DB::statement("
                ALTER TABLE `products`
                ADD CONSTRAINT `products_created_by_fk`
                FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
                ON DELETE SET NULL
            ");
        }
    }

    public function down(): void
    {
        // Helper drop FK if exists
        $dropFkIfExists = function (string $name) {
            $exists = DB::selectOne("
                SELECT 1
                FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'products'
                  AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                  AND CONSTRAINT_NAME = ?
            ", [$name]);

            if ($exists) {
                DB::statement("ALTER TABLE `products` DROP FOREIGN KEY `{$name}`");
            }
        };

        $dropFkIfExists('products_store_location_id_fk');
        $dropFkIfExists('products_created_by_fk');
        $dropFkIfExists('1'); // just in case

        // (opsional) drop index & kolom — sesuaikan kebijakan rollback kamu
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'store_location_id')) {
                // drop index jika ada
                try { $table->dropIndex('products_store_location_id_index'); } catch (\Throwable $e) {}
                // $table->dropColumn('store_location_id'); // uncomment jika ingin benar2 menghapus kolom pada rollback
            }
            if (Schema::hasColumn('products', 'created_by')) {
                try { $table->dropIndex('products_created_by_index'); } catch (\Throwable $e) {}
                // $table->dropColumn('created_by'); // uncomment jika ingin benar2 menghapus kolom pada rollback
            }
        });
    }
};
