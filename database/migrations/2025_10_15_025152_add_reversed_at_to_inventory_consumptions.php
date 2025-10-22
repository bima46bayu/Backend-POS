<?php

// database/migrations/2025_10_14_000001_add_reversed_at_to_inventory_consumptions.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('inventory_consumptions', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('created_at');
            $table->index(['layer_id','reversed_at']);
            $table->index(['sale_id','reversed_at']);
        });
    }
    public function down(): void {
        Schema::table('inventory_consumptions', function (Blueprint $table) {
            $table->dropIndex(['layer_id','reversed_at']);
            $table->dropIndex(['sale_id','reversed_at']);
            $table->dropColumn('reversed_at');
        });
    }
};

