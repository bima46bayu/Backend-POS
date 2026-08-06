<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_product_option_group', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('product_option_group_id')
                ->constrained('product_option_groups')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['product_id', 'product_option_group_id'],
                'product_option_group_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_product_option_group');
    }
};
