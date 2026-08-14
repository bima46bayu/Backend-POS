<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let option qty deltas use the same Amount + Unit UX as product recipes.
 * At sale time the delta is converted into the ingredient's stock unit.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_option_values', function (Blueprint $table) {
            if (! Schema::hasColumn('product_option_values', 'qty_delta_unit_id')) {
                $table->foreignId('qty_delta_unit_id')
                    ->nullable()
                    ->after('qty_delta')
                    ->constrained('units')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_option_values', function (Blueprint $table) {
            if (Schema::hasColumn('product_option_values', 'qty_delta_unit_id')) {
                $table->dropConstrainedForeignId('qty_delta_unit_id');
            }
        });
    }
};
