<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('stock_reconciliations', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('reference_code', 50)->unique()->nullable(); // e.g. OPN-2025-11-0001
            $t->unsignedBigInteger('store_location_id')->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->enum('status', ['DRAFT','APPLIED','REVERSED'])->default('DRAFT');
            $t->date('date_from')->nullable();
            $t->date('date_to')->nullable();
            $t->timestamp('reconciled_at')->nullable();
            $t->text('note')->nullable();

            // ringkasan opsional
            $t->unsignedInteger('total_items')->default(0);
            $t->decimal('total_value', 18, 2)->default(0);

            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('stock_reconciliations');
    }
};
