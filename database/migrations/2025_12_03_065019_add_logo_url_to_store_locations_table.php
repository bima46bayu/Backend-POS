<?php

// database/migrations/xxxx_xx_xx_xxxxxx_add_logo_url_to_store_locations_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('store_locations', function (Blueprint $table) {
            $table->string('logo_url')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('store_locations', function (Blueprint $table) {
            $table->dropColumn('logo_url');
        });
    }
};

