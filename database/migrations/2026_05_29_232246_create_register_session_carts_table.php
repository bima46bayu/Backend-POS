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
        if (Schema::hasTable('register_session_carts')) {
            return;
        }

        Schema::create('register_session_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('register_session_id')
                  ->unique()
                  ->constrained('register_sessions')
                  ->cascadeOnDelete();
            $table->json('items')->nullable();
            $table->json('checkout')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('register_session_carts');
    }
};
