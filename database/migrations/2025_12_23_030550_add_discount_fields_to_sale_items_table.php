<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_items', 'discount_id')) {
                $table->unsignedBigInteger('discount_id')->nullable()->after('unit_price');
                $table->index('discount_id');
            }
            if (!Schema::hasColumn('sale_items', 'discount_name')) {
                $table->string('discount_name')->nullable()->after('discount_id');
            }
            if (!Schema::hasColumn('sale_items', 'discount_kind')) {
                $table->string('discount_kind')->nullable()->after('discount_name');
            }
            if (!Schema::hasColumn('sale_items', 'discount_value')) {
                $table->decimal('discount_value', 18, 2)->nullable()->after('discount_kind');
            }

            // sale_items.discount_nominal (kolom lama) tetap dipakai sebagai NOMINAL diskon item.
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (Schema::hasColumn('sale_items', 'discount_value')) $table->dropColumn('discount_value');
            if (Schema::hasColumn('sale_items', 'discount_kind'))  $table->dropColumn('discount_kind');
            if (Schema::hasColumn('sale_items', 'discount_name'))  $table->dropColumn('discount_name');
            if (Schema::hasColumn('sale_items', 'discount_id'))    $table->dropIndex(['discount_id']);
            if (Schema::hasColumn('sale_items', 'discount_id'))    $table->dropColumn('discount_id');
        });
    }
};
