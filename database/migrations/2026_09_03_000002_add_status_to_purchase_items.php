<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PO lines are never hard-deleted. Untouched GR layers cascade-delete;
 * the commercial PO row stays with status = cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_items')) {
            return;
        }

        Schema::table('purchase_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_items', 'status')) {
                $table->string('status', 16)->default('open')->after('line_total');
            }
            if (! Schema::hasColumn('purchase_items', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('purchase_items', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            }
            if (! Schema::hasColumn('purchase_items', 'cancelled_note')) {
                $table->text('cancelled_note')->nullable()->after('cancelled_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_items')) {
            return;
        }

        Schema::table('purchase_items', function (Blueprint $table) {
            $drop = [];
            foreach (['status', 'cancelled_at', 'cancelled_by', 'cancelled_note'] as $col) {
                if (Schema::hasColumn('purchase_items', $col)) {
                    $drop[] = $col;
                }
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
