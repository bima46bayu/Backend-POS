<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member / customer database.
 *
 * Scoping: a member belongs to a PARENT store group (store_location_id always
 * holds the region root id). A card issued at Tanabambu therefore works at every
 * branch under Instafactory, with a single pooled point balance.
 *
 * `points_balance` is a denormalised running total for fast reads; the source of
 * truth is member_point_transactions. Keeping both lets the POS show a balance
 * without aggregating a ledger on every lookup, while still allowing an exact
 * rebuild / audit.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('members')) {
            return;
        }

        Schema::create('members', function (Blueprint $table) {
            $table->id();

            // Region root id (parent store). Never a branch id.
            $table->foreignId('store_location_id')
                ->constrained('store_locations')
                ->cascadeOnDelete();

            // Human-facing card code, e.g. MBR-0001. Unique per store group.
            $table->string('code', 40);

            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('address', 255)->nullable();
            $table->text('note')->nullable();

            // Running totals (denormalised; ledger is authoritative).
            $table->integer('points_balance')->default(0);
            $table->integer('points_earned_total')->default(0);
            $table->integer('points_spent_total')->default(0);

            // Lifetime spend / visits, used for reporting and tier ideas later.
            $table->decimal('total_spend', 18, 2)->default(0);
            $table->unsignedInteger('visit_count')->default(0);
            $table->timestamp('last_transaction_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Code and phone must be unique WITHIN a store group, not globally:
            // two different businesses may legitimately have the same customer.
            $table->unique(['store_location_id', 'code']);
            $table->unique(['store_location_id', 'phone']);

            $table->index(['store_location_id', 'name']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
