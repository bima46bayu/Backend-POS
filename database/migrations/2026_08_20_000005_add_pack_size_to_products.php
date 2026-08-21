<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pack-based purchasing: you buy a Pack of 100 straws for Rp 5.000 but sell and
 * stock them per Pcs.
 *
 * Without this, whoever receives the goods has to divide 5000 by 100 in their
 * head and type 50. `products.unit_id` is only a label and
 * UnitConversionService only knows Kg<->Gram and L<->Ml, so there was no
 * Pack->Pcs path at all.
 *
 * Two columns, both nullable:
 *   - pack_size: how many stock units are in one purchase pack (100)
 *   - pack_label: what the pack is called, for the UI only ("Pack", "Box")
 *
 * NULL pack_size means "bought in the same unit it's stocked in" — the existing
 * behaviour, so nothing changes for products that don't need this.
 *
 * Deliberately NOT storing a pack price: cost_price stays the single per-stock-unit
 * cost that every inventory/COGS path already reads. Pack price is an input to
 * derive it, not a second source of truth to drift out of sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'pack_size')) {
                // Decimal, not integer: a "pack" can legitimately be 1.5 kg.
                $table->decimal('pack_size', 12, 4)->nullable()->after('cost_price');
            }
            if (! Schema::hasColumn('products', 'pack_label')) {
                $table->string('pack_label', 32)->nullable()->after('pack_size');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['pack_size', 'pack_label'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
