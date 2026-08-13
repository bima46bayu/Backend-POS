<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a sale to a member.
 *
 * NOTE: the existing `customer_name` column is deliberately left untouched. In
 * the POS it holds a customer *type* (General / Retail / Member / ...), and
 * History, receipts and CSV exports all read it. Adding member_id alongside it
 * keeps every one of those working unchanged.
 *
 * `points_earned` is snapshotted on the sale so a receipt can print
 * "Poin didapat: 3" without touching the ledger.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'member_id')) {
                $table->foreignId('member_id')
                    ->nullable()
                    ->after('customer_name')
                    ->constrained('members')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('sales', 'points_earned')) {
                $table->integer('points_earned')->default(0)->after('member_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'member_id')) {
                $table->dropForeign(['member_id']);
                $table->dropColumn('member_id');
            }
            if (Schema::hasColumn('sales', 'points_earned')) {
                $table->dropColumn('points_earned');
            }
        });
    }
};
