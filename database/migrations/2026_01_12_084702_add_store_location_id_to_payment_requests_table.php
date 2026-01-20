<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // database/migrations/xxxx_add_store_location_id_to_payment_requests_table.php
        public function up(): void
        {
            Schema::table('payment_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('store_location_id')->after('id');

                $table->foreign('store_location_id')
                    ->references('id')->on('store_locations')
                    ->cascadeOnDelete();
            });
        }

        public function down(): void
        {
            Schema::table('payment_requests', function (Blueprint $table) {
                $table->dropForeign(['store_location_id']);
                $table->dropColumn('store_location_id');
            });
        }
};
