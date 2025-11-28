<?php

// database/migrations/2025_11_XX_XXXXXX_add_store_location_to_purchases.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('store_location_id')->nullable()->after('user_id');

            $table->foreign('store_location_id')
                ->references('id')
                ->on('store_locations')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['store_location_id']);
            $table->dropColumn('store_location_id');
        });
    }
};

