<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_option_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_option_group_id')
                ->constrained('product_option_groups')
                ->cascadeOnDelete();

            // e.g. "No Sugar", "Less Ice"
            $table->string('name', 100);

            // tambahan harga per unit (Rp). 0 = gratis
            $table->decimal('price_delta', 12, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['product_option_group_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_values');
    }
};
