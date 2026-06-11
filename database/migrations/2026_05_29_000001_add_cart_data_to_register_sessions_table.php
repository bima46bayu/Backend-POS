<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('register_sessions', function (Blueprint $table) {
            $table->json('cart_data')->nullable()->after('note_close');
        });
    }

    public function down(): void
    {
        Schema::table('register_sessions', function (Blueprint $table) {
            $table->dropColumn('cart_data');
        });
    }
};
