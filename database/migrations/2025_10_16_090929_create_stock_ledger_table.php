<?php

// database/migrations/2025_10_16_000001_create_stock_ledger_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('stock_ledger')) {
            Schema::create('stock_ledger', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('product_id')->index();
                $t->unsignedBigInteger('store_location_id')->nullable()->index();
                $t->unsignedBigInteger('layer_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();

                $t->string('ref_type', 32)->nullable()->index(); // 'GR','SALE',dst (UPPERCASE biar cocok controller kamu)
                $t->unsignedBigInteger('ref_id')->nullable()->index();

                // arah pakai numerik: IN = +1, OUT = -1 (sesuai InventoryController kamu)
                $t->tinyInteger('direction'); // -1 / +1
                $t->unsignedInteger('qty');

                $t->decimal('unit_cost', 15, 2)->nullable();
                $t->decimal('unit_price', 15, 2)->nullable();
                $t->decimal('subtotal_cost', 18, 2)->nullable();

                $t->string('note', 255)->nullable();
                $t->timestamps();

                $t->index(['product_id','store_location_id','created_at']);
            });
        }
    }

    public function down(): void {
        // kalau perlu:
        // Schema::dropIfExists('stock_ledger');
    }
};

