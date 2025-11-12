<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('stock_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->string('name',150);
            $t->unsignedBigInteger('store_location_id');
            $t->string('status',20)->default('DRAFT'); // DRAFT|APPLIED
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('applied_by')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();

            $t->index(['store_location_id','status']);
        });
    }
    public function down(): void { Schema::dropIfExists('stock_reconciliations'); }
};
