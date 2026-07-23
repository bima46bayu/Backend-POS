<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 120)->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
        }

        $defaults = [
            'submitted' => [
                'label' => 'Diajukan Oleh,',
                'name' => 'Nuramelia Hakim',
                'signature' => 'signatures/sig-amel.png',
                'show_signature' => true,
            ],
            'acknowledged' => [
                'label' => 'Diketahui Oleh,',
                'name' => 'Susi Kartika C.',
                'signature' => 'signatures/sig-bu-susi.png',
                'show_signature' => true,
            ],
            'approved' => [
                'label' => 'Disetujui Oleh,',
                'name' => 'Song JungLog',
                'signature' => null,
                'show_signature' => false,
            ],
        ];

        $exists = DB::table('app_settings')
            ->where('key', 'payment_request.signatories')
            ->exists();

        if (!$exists) {
            DB::table('app_settings')->insert([
                'key' => 'payment_request.signatories',
                'value' => json_encode($defaults),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
