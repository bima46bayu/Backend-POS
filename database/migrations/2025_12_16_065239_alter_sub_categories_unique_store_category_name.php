<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sub_categories', function (Blueprint $table) {
            // Kalau DI PASTIIN store_location_id SUDAH ADA di tabel,
            // kamu boleh hapus blok komentar ini.
            /*
            if (!Schema::hasColumn('sub_categories', 'store_location_id')) {
                $table->unsignedBigInteger('store_location_id')->nullable()->after('id');
                // Kalau mau tambah FK:
                // $table->foreign('store_location_id')
                //       ->references('id')->on('store_locations')
                //       ->nullOnDelete();
            }
            */

            // UNIQUE GABUNGAN: store + category + name
            $table->unique(
                ['store_location_id', 'category_id', 'name'],
                'sub_categories_store_cat_name_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('sub_categories', function (Blueprint $table) {
            // Hapus unique yang baru kita buat
            $table->dropUnique('sub_categories_store_cat_name_unique');

            // Kalau di up() kamu nambah kolom store_location_id,
            // di sini bisa sekalian di-drop (kalau mau benar-benar rollback):
            // $table->dropColumn('store_location_id');
        });
    }
};
