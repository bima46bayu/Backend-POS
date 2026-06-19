<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_location_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'store_location_id']);
        });

        Schema::create('product_recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('product_recipes')->cascadeOnDelete();
            $table->foreignId('ingredient_product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('qty', 12, 4);
            $table->timestamps();

            $table->unique(['recipe_id', 'ingredient_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_recipe_items');
        Schema::dropIfExists('product_recipes');
    }
};
