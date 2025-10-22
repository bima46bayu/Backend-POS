<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('inventory_consumptions', function (Blueprint $t) {
      $t->id();

      $t->unsignedBigInteger('product_id');
      $t->unsignedBigInteger('store_location_id')->nullable();
      $t->unsignedBigInteger('sale_id')->nullable();
      $t->unsignedBigInteger('sale_item_id')->nullable();
      $t->unsignedBigInteger('layer_id');

      // ===== INT untuk kuantitas konsumsi =====
      $t->unsignedInteger('qty');

      // Biaya tetap desimal
      $t->decimal('unit_cost', 16, 2);

      $t->timestamps();

      $t->index(['sale_item_id'], 'idx_ic_sale_item');
      $t->index(['product_id','store_location_id'], 'idx_ic_prod_store');
      $t->index(['layer_id'], 'idx_ic_layer');

      // (opsional) FK kalau tabel terkait sudah ada
      // $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
      // $t->foreign('layer_id')->references('id')->on('inventory_layers')->cascadeOnDelete();
      // $t->foreign('sale_item_id')->references('id')->on('sale_items')->cascadeOnDelete();
      // $t->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
    });
  }

  public function down(): void {
    Schema::dropIfExists('inventory_consumptions');
  }
};
