<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline-first support for the mobile POS.
 *
 * When the connection dies the cashier keeps selling; the sale is queued on the
 * device and pushed later. Those pushes need:
 *
 *  - client_uuid        : idempotency key. A retry (timeout that actually
 *                         succeeded server-side) must NOT create a second sale.
 *  - offline_created_at : the real time the customer paid, so reports and the
 *                         register session bucket it correctly instead of
 *                         "whenever the phone got signal".
 *  - is_offline         : flag for the UI / audit.
 *  - stock_shortfall    : what could not be covered by stock at sync time.
 *                         The money was already taken so we record the sale
 *                         anyway and flag the gap for a manager.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'client_uuid')) {
                $table->uuid('client_uuid')->nullable()->after('code');
                $table->unique('client_uuid');
            }

            if (! Schema::hasColumn('sales', 'is_offline')) {
                $table->boolean('is_offline')->default(false)->after('status');
            }

            if (! Schema::hasColumn('sales', 'offline_created_at')) {
                $table->timestamp('offline_created_at')->nullable()->after('is_offline');
            }

            if (! Schema::hasColumn('sales', 'synced_at')) {
                $table->timestamp('synced_at')->nullable()->after('offline_created_at');
            }

            if (! Schema::hasColumn('sales', 'stock_shortfall')) {
                $table->json('stock_shortfall')->nullable()->after('synced_at');
            }

            if (! Schema::hasColumn('sales', 'needs_review')) {
                $table->boolean('needs_review')->default(false)->after('stock_shortfall');
                $table->index('needs_review');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'client_uuid')) {
                $table->dropUnique(['client_uuid']);
                $table->dropColumn('client_uuid');
            }
            if (Schema::hasColumn('sales', 'needs_review')) {
                $table->dropIndex(['needs_review']);
            }

            foreach ([
                'is_offline',
                'offline_created_at',
                'synced_at',
                'stock_shortfall',
                'needs_review',
            ] as $col) {
                if (Schema::hasColumn('sales', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
