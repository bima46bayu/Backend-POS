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
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_number')->unique();        // PO-YYYYMM-####
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // pembuat
            $table->date('order_date')->default(DB::raw('CURRENT_DATE'));
            $table->date('expected_date')->nullable();

            // ringkasan
            $table->decimal('subtotal',14,2)->default(0);
            $table->decimal('tax_total',14,2)->default(0);
            $table->decimal('other_cost',14,2)->default(0);
            $table->decimal('grand_total',14,2)->default(0);

            $table->enum('status',['draft','approved','partially_received','closed','canceled'])->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['supplier_id','order_date']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
