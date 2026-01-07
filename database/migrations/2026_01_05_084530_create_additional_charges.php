<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('additional_charges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_location_id')
                ->constrained()
                ->cascadeOnDelete();

            // PB1 / SERVICE
            $table->enum('type', ['PB1', 'SERVICE']);

            // PERCENT / FIXED
            $table->enum('calc_type', ['PERCENT', 'FIXED']);

            // 10 = 10% | 5000 = Rp 5.000
            $table->decimal('value', 12, 2);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // ⛔ constraint penting
            $table->unique(['store_location_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_charges');
    }
};
