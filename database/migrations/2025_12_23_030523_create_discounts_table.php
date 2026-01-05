<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('scope', ['GLOBAL','ITEM']);   // diskon transaksi / per item
            $table->enum('kind', ['PERCENT','FIXED']);  // persen / nominal tetap
            $table->decimal('value', 18, 2);            // 10 => 10%, 5000 => Rp 5.000
            $table->decimal('max_amount', 18, 2)->nullable();  // cap utk persen (opsional)
            $table->decimal('min_subtotal', 18, 2)->nullable(); // minimal base (opsional)
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('store_location_id')->nullable(); // opsional per store
            $table->timestamps();

            $table->index(['active','scope']);
            $table->index('store_location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
