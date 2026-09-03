<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FIFO cost-layer lifecycle: layers become append-only once consumed.
 * Status + reversal qty live on the layer; COGS corrections go to
 * cost_adjustments rather than mutating historical GR/sale rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_layers')) {
            Schema::table('inventory_layers', function (Blueprint $table) {
                if (! Schema::hasColumn('inventory_layers', 'status')) {
                    $table->string('status', 16)->default('open')->after('qty_remaining');
                }
                if (! Schema::hasColumn('inventory_layers', 'qty_reversed')) {
                    $table->decimal('qty_reversed', 16, 4)->default(0)->after('qty_remaining');
                }
                if (! Schema::hasColumn('inventory_layers', 'consumed_review_flagged')) {
                    $table->boolean('consumed_review_flagged')->default(false);
                }
            });

            if (Schema::hasColumn('inventory_layers', 'status')) {
                DB::table('inventory_layers')
                    ->whereNull('status')
                    ->orWhere('status', '')
                    ->update(['status' => 'open']);

                DB::table('inventory_layers')
                    ->where('status', 'open')
                    ->where('qty_remaining', '<=', 0)
                    ->update(['status' => 'closed']);
            }
        }

        if (Schema::hasTable('goods_receipts')) {
            Schema::table('goods_receipts', function (Blueprint $table) {
                if (! Schema::hasColumn('goods_receipts', 'reversed_at')) {
                    $table->timestamp('reversed_at')->nullable();
                }
                if (! Schema::hasColumn('goods_receipts', 'review_flagged_at')) {
                    $table->timestamp('review_flagged_at')->nullable();
                }
                if (! Schema::hasColumn('goods_receipts', 'review_flagged_by')) {
                    $table->unsignedBigInteger('review_flagged_by')->nullable();
                }
                if (! Schema::hasColumn('goods_receipts', 'review_reason')) {
                    $table->text('review_reason')->nullable();
                }
            });
        }

        if (Schema::hasTable('goods_receipt_items')
            && ! Schema::hasColumn('goods_receipt_items', 'qty_reversed')
        ) {
            Schema::table('goods_receipt_items', function (Blueprint $table) {
                $table->decimal('qty_reversed', 16, 4)->default(0)->after('qty_received');
            });
        }

        if (! Schema::hasTable('cost_adjustments')) {
            Schema::create('cost_adjustments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('original_movement_id')->nullable()->index();
                $table->unsignedBigInteger('layer_id')->nullable()->index();
                $table->unsignedBigInteger('goods_receipt_id')->nullable()->index();
                $table->unsignedBigInteger('goods_receipt_item_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('store_location_id')->nullable()->index();
                $table->decimal('qty_affected', 16, 4);
                $table->decimal('old_unit_cost', 16, 2);
                $table->decimal('new_unit_cost', 16, 2);
                $table->decimal('cogs_delta', 18, 2);
                $table->text('reason');
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_adjustments');

        if (Schema::hasTable('goods_receipt_items')
            && Schema::hasColumn('goods_receipt_items', 'qty_reversed')
        ) {
            Schema::table('goods_receipt_items', function (Blueprint $table) {
                $table->dropColumn('qty_reversed');
            });
        }

        if (Schema::hasTable('goods_receipts')) {
            Schema::table('goods_receipts', function (Blueprint $table) {
                $drop = [];
                foreach (['reversed_at', 'review_flagged_at', 'review_flagged_by', 'review_reason'] as $col) {
                    if (Schema::hasColumn('goods_receipts', $col)) {
                        $drop[] = $col;
                    }
                }
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }

        if (Schema::hasTable('inventory_layers')) {
            Schema::table('inventory_layers', function (Blueprint $table) {
                $drop = [];
                foreach (['status', 'qty_reversed', 'consumed_review_flagged'] as $col) {
                    if (Schema::hasColumn('inventory_layers', $col)) {
                        $drop[] = $col;
                    }
                }
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }
    }
};
