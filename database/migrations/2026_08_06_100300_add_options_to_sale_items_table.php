<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            // Snapshot pilihan opsi item (label + harga), supaya struk lama
            // tetap benar walau master opsi berubah/dihapus.
            // [{ "group": "Sugar Level", "name": "Less Sugar", "price_delta": 0 }]
            if (! Schema::hasColumn('sale_items', 'options')) {
                $table->json('options')->nullable()->after('product_id');
            }

            // total tambahan harga opsi per unit (Rp), sudah termasuk di unit_price
            if (! Schema::hasColumn('sale_items', 'options_price')) {
                $table->decimal('options_price', 12, 2)->default(0)->after('options');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (Schema::hasColumn('sale_items', 'options_price')) {
                $table->dropColumn('options_price');
            }
            if (Schema::hasColumn('sale_items', 'options')) {
                $table->dropColumn('options');
            }
        });
    }
};
