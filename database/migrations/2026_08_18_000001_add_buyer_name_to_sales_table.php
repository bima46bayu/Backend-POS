<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text person name for a sale ("who is this order for").
 *
 * `customer_name` stays the customer *type* (General / Retail / Member / ...).
 * Putting the person's name in that column would break type filters and
 * reports, so this lives beside it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'buyer_name')) {
                $table->string('buyer_name')->nullable()->after('customer_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'buyer_name')) {
                $table->dropColumn('buyer_name');
            }
        });
    }
};
