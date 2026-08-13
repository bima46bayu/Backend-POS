<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point ledger — append-only history of every point change.
 *
 * Why a ledger instead of just members.points_balance:
 *  - A voided sale must claw back exactly the points it granted. With only a
 *    counter we would have to recompute from the rate, which breaks the moment
 *    the admin changes the rate.
 *  - Offline sales sync late; `sale_id` + a unique index make awarding
 *    idempotent, so a re-pushed sale cannot grant points twice.
 *  - Members can be shown a statement of how they earned/spent.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('member_point_transactions')) {
            return;
        }

        Schema::create('member_point_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('member_id')
                ->constrained('members')
                ->cascadeOnDelete();

            // EARN (from a sale), REVOKE (sale voided), ADJUST (manual by admin).
            $table->string('type', 20);

            // Signed: positive adds, negative removes.
            $table->integer('points');

            // Balance after applying this row — makes the ledger auditable
            // without replaying it from the beginning.
            $table->integer('balance_after');

            // Source sale, when the change came from a transaction.
            $table->foreignId('sale_id')
                ->nullable()
                ->constrained('sales')
                ->nullOnDelete();

            // Sale total the points were computed from, and the rate in force at
            // that moment. Stored so history stays truthful after a rate change.
            $table->decimal('amount', 18, 2)->default(0);
            $table->integer('rate_per_point')->nullable();

            // Branch where it happened (members are parent-scoped, but we still
            // want to know which cabang served them).
            $table->foreignId('store_location_id')
                ->nullable()
                ->constrained('store_locations')
                ->nullOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('note', 255)->nullable();

            $table->timestamps();

            // One EARN row per sale, ever. This is what makes offline re-sync
            // and retried requests safe.
            $table->unique(['sale_id', 'type']);

            $table->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_point_transactions');
    }
};
