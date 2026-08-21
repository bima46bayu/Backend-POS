<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') return;
        DB::statement(
            "ALTER TABLE users MODIFY COLUMN role ENUM('admin','regional_manager','store_admin','kasir') NOT NULL DEFAULT 'kasir'"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') return;
        DB::table('users')->whereIn('role', ['regional_manager', 'store_admin'])->update(['role' => 'kasir']);

        DB::statement(
            "ALTER TABLE users MODIFY COLUMN role ENUM('admin','kasir') NOT NULL DEFAULT 'kasir'"
        );
    }
};
