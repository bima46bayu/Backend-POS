<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // nullable dulu supaya aman untuk data existing
            $table->foreignId('unit_id')
                ->nullable()
                ->after('name') // sesuaikan posisi
                ->constrained('units')
                ->nullOnDelete(); // kalau unit dihapus, unit_id jadi null (atau bisa restrict, tergantung kebijakan)
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');
        });
    }
};

