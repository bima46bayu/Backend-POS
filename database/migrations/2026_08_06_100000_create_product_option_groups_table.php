<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_option_groups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_location_id')
                ->constrained()
                ->cascadeOnDelete();

            // e.g. "Sugar Level", "Ice Level"
            $table->string('name', 100);

            // SINGLE = pilih satu, MULTI = boleh pilih beberapa
            $table->enum('selection_type', ['SINGLE', 'MULTI'])->default('SINGLE');

            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['store_location_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_groups');
    }
};
