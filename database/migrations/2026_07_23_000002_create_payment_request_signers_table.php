<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('payment_request_signers')) {
            Schema::create('payment_request_signers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('signature', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // Seed people from previous flat config (or defaults), without wiping existing rows.
        if (DB::table('payment_request_signers')->count() === 0) {
            $legacy = null;
            if (Schema::hasTable('app_settings')) {
                $row = DB::table('app_settings')
                    ->where('key', 'payment_request.signatories')
                    ->first();
                if ($row && $row->value) {
                    $legacy = json_decode($row->value, true);
                }
            }

            $defaults = [
                'submitted' => [
                    'name' => 'Nuramelia Hakim',
                    'signature' => 'signatures/sig-amel.png',
                ],
                'acknowledged' => [
                    'name' => 'Susi Kartika C.',
                    'signature' => 'signatures/sig-bu-susi.png',
                ],
                'approved' => [
                    'name' => 'Song JungLog',
                    'signature' => null,
                ],
            ];

            $now = now();
            $ids = [];
            foreach (['submitted', 'acknowledged', 'approved'] as $role) {
                $src = is_array($legacy[$role] ?? null) ? $legacy[$role] : $defaults[$role];
                $name = trim((string) ($src['name'] ?? $defaults[$role]['name']));
                $signature = $src['signature'] ?? $defaults[$role]['signature'];
                if (is_string($signature)) {
                    $signature = trim($signature);
                    $signature = $signature === '' ? null : ltrim(str_replace('\\', '/', $signature), '/');
                } else {
                    $signature = null;
                }

                $ids[$role] = DB::table('payment_request_signers')->insertGetId([
                    'name' => $name !== '' ? $name : $defaults[$role]['name'],
                    'signature' => $signature,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $labels = [
                'submitted' => (string) ($legacy['submitted']['label'] ?? 'Diajukan Oleh,'),
                'acknowledged' => (string) ($legacy['acknowledged']['label'] ?? 'Diketahui Oleh,'),
                'approved' => (string) ($legacy['approved']['label'] ?? 'Disetujui Oleh,'),
            ];

            $assignments = [
                'submitted' => [
                    'signer_id' => $ids['submitted'],
                    'label' => $labels['submitted'],
                ],
                'acknowledged' => [
                    'signer_id' => $ids['acknowledged'],
                    'label' => $labels['acknowledged'],
                ],
                'approved' => [
                    'signer_id' => $ids['approved'],
                    'label' => $labels['approved'],
                ],
            ];

            if (Schema::hasTable('app_settings')) {
                $exists = DB::table('app_settings')
                    ->where('key', 'payment_request.signer_roles')
                    ->exists();

                if ($exists) {
                    DB::table('app_settings')
                        ->where('key', 'payment_request.signer_roles')
                        ->update([
                            'value' => json_encode($assignments),
                            'updated_at' => $now,
                        ]);
                } else {
                    DB::table('app_settings')->insert([
                        'key' => 'payment_request.signer_roles',
                        'value' => json_encode($assignments),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_request_signers');
        if (Schema::hasTable('app_settings')) {
            DB::table('app_settings')->where('key', 'payment_request.signer_roles')->delete();
        }
    }
};
