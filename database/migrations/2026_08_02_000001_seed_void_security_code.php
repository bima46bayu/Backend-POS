<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        $key = 'sales.void_security_code';
        $exists = DB::table('app_settings')->where('key', $key)->exists();
        if ($exists) {
            return;
        }

        DB::table('app_settings')->insert([
            'key' => $key,
            'value' => json_encode([
                'hash' => Hash::make('2580'),
                'updated_at' => now()->toIso8601String(),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        DB::table('app_settings')->where('key', 'sales.void_security_code')->delete();
    }
};
