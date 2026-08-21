<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog of prizes members can buy with points ("Member Store").
 *
 * Scoped to the parent store group, same as members, so a reward defined for
 * Tanabambu/Instafactory is redeemable at every branch in that group.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('loyalty_rewards')) {
            Schema::create('loyalty_rewards', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_location_id')
                    ->constrained('store_locations')
                    ->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('description', 255)->nullable();
                $table->unsignedInteger('points_cost');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['store_location_id', 'is_active']);
            });
        }

        if (Schema::hasTable('member_point_transactions')
            && ! Schema::hasColumn('member_point_transactions', 'loyalty_reward_id')) {
            Schema::table('member_point_transactions', function (Blueprint $table) {
                $table->foreignId('loyalty_reward_id')
                    ->nullable()
                    ->after('sale_id')
                    ->constrained('loyalty_rewards')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('member_point_transactions')
            && Schema::hasColumn('member_point_transactions', 'loyalty_reward_id')) {
            Schema::table('member_point_transactions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('loyalty_reward_id');
            });
        }

        Schema::dropIfExists('loyalty_rewards');
    }
};
