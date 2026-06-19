<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_recipe_items', function (Blueprint $table) {
            $table->foreignId('unit_id')
                ->nullable()
                ->after('qty')
                ->constrained('units')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_recipe_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
        });
    }
};
