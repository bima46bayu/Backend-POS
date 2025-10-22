<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('inventory_layers', function (Blueprint $t) {
      $t->id();

      // Relasi dasar
      $t->unsignedBigInteger('product_id');
      $t->unsignedBigInteger('store_location_id')->nullable();

      // Sumber layer (izinkan ADD_PRODUCT sejak awal)
      $t->enum('source_type', ['ADD_PRODUCT','GR','ADJUSTMENT_IN','IMPORT_INIT'])->nullable();
      $t->unsignedBigInteger('source_id')->nullable();

      // Biaya (tetap decimal)
      $t->decimal('unit_price',       16, 2)->nullable(); // harga jual referensi (opsional)
      $t->decimal('unit_tax',         16, 2)->default(0);
      $t->decimal('unit_other_cost',  16, 2)->default(0);
      $t->decimal('unit_landed_cost', 16, 2)->default(0);
      $t->decimal('unit_cost',        16, 2)->default(0);
      $t->decimal('estimated_cost',   16, 2)->nullable();

      // Qty: INTEGER (pcs)
      $t->unsignedInteger('qty_initial');
      $t->unsignedInteger('qty_remaining');

      $t->date('expiry_date')->nullable();
      $t->string('note', 255)->nullable();

      $t->timestamps();

      // Indexes
      $t->index(['product_id','store_location_id','created_at'], 'idx_il_prod_store_created');
      $t->index(['product_id','store_location_id','qty_remaining'], 'idx_il_prod_store_qty');

      // (opsional) FK kalau tabel terkait sudah ada
      // $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
      // $t->foreign('store_location_id')->references('id')->on('store_locations')->nullOnDelete();
    });
  }

  public function down(): void {
    Schema::dropIfExists('inventory_layers');
  }
};
