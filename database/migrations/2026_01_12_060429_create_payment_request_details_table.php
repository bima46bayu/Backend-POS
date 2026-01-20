<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_request_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_request_id');
            $table->unsignedBigInteger('payee_id');
            $table->unsignedBigInteger('coa_id');
            $table->string('description')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('deduction', 18, 2)->default(0);
            $table->decimal('transfer_amount', 18, 2);
            $table->string('remark')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_request_details');
    }
};
