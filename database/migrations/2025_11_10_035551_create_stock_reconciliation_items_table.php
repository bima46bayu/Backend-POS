<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('stock_reconciliation_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('reconciliation_id')->index();
            $t->unsignedBigInteger('product_id')->index();

            $t->string('sku', 100)->nullable();
            $t->string('name')->nullable();

            $t->decimal('system_stock', 18, 3)->default(0);
            $t->decimal('real_stock', 18, 3)->nullable();    // diisi setelah upload/input
            $t->decimal('avg_cost', 18, 2)->default(0);      // rata" modal periode
            $t->decimal('diff_stock', 18, 3)->default(0);    // real - system
            $t->enum('direction', ['NONE','IN','OUT'])->default('NONE');

            $t->string('note', 255)->nullable();

            $t->timestamps();

            $t->unique(['reconciliation_id','product_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('stock_reconciliation_items');
    }
};
