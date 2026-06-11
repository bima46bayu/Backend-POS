<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('register_session_carts')) {
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

        // Move any legacy cart_data from register_sessions into the new table
        if (Schema::hasColumn('register_sessions', 'cart_data')) {
            $sessions = DB::table('register_sessions')
                ->whereNotNull('cart_data')
                ->whereNull('closed_at')
                ->get(['id', 'cart_data']);

            foreach ($sessions as $row) {
                $decoded = is_string($row->cart_data)
                    ? json_decode($row->cart_data, true)
                    : (array) $row->cart_data;

                if (!is_array($decoded)) {
                    continue;
                }

                DB::table('register_session_carts')->insert([
                    'register_session_id' => $row->id,
                    'items'               => json_encode($decoded['items'] ?? []),
                    'checkout'            => json_encode($decoded['checkout'] ?? null),
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
            }

            Schema::table('register_sessions', function (Blueprint $table) {
                $table->dropColumn('cart_data');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('register_session_carts');

        if (!Schema::hasColumn('register_sessions', 'cart_data')) {
            Schema::table('register_sessions', function (Blueprint $table) {
                $table->json('cart_data')->nullable()->after('note_close');
            });
        }
    }
};
