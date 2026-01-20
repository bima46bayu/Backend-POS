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
        Schema::create('payment_request_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_request_id');
            $table->unsignedBigInteger('bank_account_id');
            $table->decimal('saldo', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(
                ['payment_request_id', 'bank_account_id'],
                'u_pr_bal_req_bank'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_request_balances');
    }
};
