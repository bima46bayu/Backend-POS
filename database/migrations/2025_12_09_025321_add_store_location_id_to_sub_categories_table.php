<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sub_categories', function (Blueprint $table) {
            $table->foreignId('store_location_id')
                ->nullable()
                ->after('id') // atau setelah category_id, suka-suka
                ->constrained('store_locations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sub_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_location_id');
        });
    }
};
