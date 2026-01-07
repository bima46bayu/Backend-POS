<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {

            // total setelah diskon (sebelum PB1 & service)
            $table->decimal('grand_total', 12, 2)
                ->after('discount');

            // snapshot PB1 & service
            $table->json('additional_charges_snapshot')
                ->nullable()
                ->after('grand_total');

            // total PB1 + service
            $table->decimal('additional_charge_total', 12, 2)
                ->default(0)
                ->after('additional_charges_snapshot');

            // total akhir yang dibayar customer
            $table->decimal('final_total', 12, 2)
                ->after('additional_charge_total');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'grand_total',
                'additional_charges_snapshot',
                'additional_charge_total',
                'final_total',
            ]);
        });
    }
};
