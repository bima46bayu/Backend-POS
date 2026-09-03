<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_delivery_logs', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 30)->index();
            $table->string('purpose', 30);
            $table->string('code', 6);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_delivery_logs');
    }
};
