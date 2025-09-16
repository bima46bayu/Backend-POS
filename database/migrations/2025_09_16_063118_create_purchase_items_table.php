<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->unsignedInteger('qty_order');
            $table->unsignedInteger('qty_received')->default(0);
            $table->decimal('unit_price',14,2);
            $table->decimal('discount',14,2)->default(0);
            $table->decimal('tax',14,2)->default(0);
            $table->decimal('line_total',14,2);

            $table->timestamps();
            $table->index('purchase_id');
            $table->index('product_id');
            // $t->unique(['purchase_id','product_id']); // opsional
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
