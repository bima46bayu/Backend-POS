<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fulfilling a reservation now issues a Rp 0 sale (so reward stock leaves
 * inventory and shows up in reporting, exactly like the cashier-side Member
 * Store redeem). We keep the link so a voided sale can be traced back to the
 * reservation that produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reward_reservations')) {
            return;
        }

        Schema::table('reward_reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reward_reservations', 'sale_id')) {
                $table->foreignId('sale_id')->nullable()->after('point_transaction_id')
                    ->constrained('sales')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('reward_reservations')) {
            return;
        }

        Schema::table('reward_reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reward_reservations', 'sale_id')) {
                $table->dropConstrainedForeignId('sale_id');
            }
        });
    }
};
