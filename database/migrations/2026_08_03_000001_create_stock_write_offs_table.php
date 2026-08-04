<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('stock_write_offs')) {
            return;
        }

        Schema::create('stock_write_offs', function (Blueprint $t) {
            $t->id();

            $t->unsignedBigInteger('store_location_id')->index();
            $t->unsignedBigInteger('product_id')->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->unsignedBigInteger('register_session_id')->nullable()->index();

            // WASTE | SPOILED | EXPIRED | DAMAGED | OTHER
            $t->string('reason', 20)->index();

            $t->unsignedInteger('qty');
            $t->decimal('unit_cost', 16, 2)->default(0);
            $t->decimal('total_cost', 18, 2)->default(0);

            $t->string('note', 255)->nullable();
            $t->timestamps();

            $t->index(['store_location_id', 'created_at'], 'idx_swo_store_created');
            $t->index(['store_location_id', 'reason', 'created_at'], 'idx_swo_store_reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_write_offs');
    }
};
