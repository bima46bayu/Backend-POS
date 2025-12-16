<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // 1) Hapus unique lama di "name" kalau ada
            // sesuaikan nama index kalau beda (cek di phpMyAdmin -> Structure)
            try {
                $table->dropUnique('categories_name_unique');
            } catch (\Throwable $e) {
                // ignore kalau memang belum ada
            }

            // 2) Pastikan kolom store_location_id ada & nullable (kalau kamu pakai global category)
            // Kalau kolomnya sudah ada, bagian ini boleh di-skip
            // $table->unsignedBigInteger('store_location_id')->nullable()->after('id');

            // 3) Unique gabungan per store
            $table->unique(['store_location_id', 'name'], 'categories_store_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_store_name_unique');
            // optional: kembalikan unique lama
            $table->unique('name');
        });
    }
};

