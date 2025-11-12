<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('stock_reconciliation_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('stock_reconciliation_id');
            $t->unsignedBigInteger('product_id');
            $t->string('sku',100)->nullable();
            $t->string('product_name',200)->nullable();
            $t->integer('system_qty')->default(0);
            $t->decimal('avg_cost', 18, 4)->default(0);
            $t->decimal('physical_qty', 18, 4)->nullable(); // bisa null sampai diisi user
            $t->timestamps();

            $t->index(['stock_reconciliation_id']);
            $t->index(['product_id']);
            $t->index(['sku']);
        });
    }
    public function down(): void { Schema::dropIfExists('stock_reconciliation_items'); }
};
