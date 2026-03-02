<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('register_sessions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_location_id');
            $table->unsignedBigInteger('cashier_id');

            $table->decimal('opening_cash', 15, 2)->default(0);
            $table->decimal('closing_cash', 15, 2)->nullable();

            $table->text('note_open')->nullable();
            $table->text('note_close')->nullable();

            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();

            $table->unsignedInteger('total_transactions')->nullable();
            $table->decimal('total_sales', 15, 2)->nullable();
            $table->decimal('cash_payments', 15, 2)->nullable();
            $table->decimal('non_cash_payments', 15, 2)->nullable();
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();

            $table->timestamps();

            $table->index(['store_location_id', 'cashier_id']);
            $table->index(['cashier_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('register_sessions');
    }
};

