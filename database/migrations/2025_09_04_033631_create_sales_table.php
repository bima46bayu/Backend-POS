<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->unsignedBigInteger('cashier_id');
            $table->string('customer_name')->nullable();

            // ringkasan header
            $table->decimal('subtotal', 12, 2);                 // total setelah diskon item, sebelum diskon header & pajak
            $table->decimal('discount', 12, 2)->default(0);     // diskon header (opsional)
            $table->decimal('service_charge', 12, 2)->default(0); // tambahan biaya layanan
            $table->decimal('tax', 12, 2)->default(0);          // pajak header
            $table->decimal('total', 12, 2);                    // grand total
            $table->decimal('paid', 12, 2)->default(0);
            $table->decimal('change', 12, 2)->default(0);
            $table->enum('status', ['completed','void'])->default('completed');
            $table->timestamps();

            $table->foreign('cashier_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('product_id');

            // harga & diskon per item
            $table->decimal('unit_price', 12, 2);              // harga asli per unit (sebelum diskon)
            $table->decimal('discount_nominal', 12, 2)->default(0); // diskon per unit (nominal)
            $table->decimal('net_unit_price', 12, 2)->default(0);   // unit_price - discount_nominal

            $table->integer('qty');
            $table->decimal('line_total', 14, 2);              // net_unit_price * qty
            $table->timestamps();

            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');

            // (opsional tapi direkomendasikan)
            $table->index(['sale_id']);
            $table->index(['product_id']);
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_id');
            $table->enum('method', ['cash','card','ewallet','transfer','QRIS']); // samakan dengan enum di sisi app
            $table->decimal('amount', 12, 2);
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');
        });
    }

    public function down(): void {
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
