<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->unsignedBigInteger('minimum_lifetime_points')->default(0);
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('loyalty_tier_perks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_tier_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('reward_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug', 120)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('title', 140);
            $table->string('description', 500)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('member_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained('members')->cascadeOnDelete();
            $table->string('phone', 30)->unique();
            $table->string('password');
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('member_otp_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('phone', 30)->index();
            $table->string('purpose', 30);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['phone', 'purpose', 'created_at']);
        });

        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->foreignId('reward_category_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->foreignId('minimum_tier_id')->nullable()->after('reward_category_id')->constrained('loyalty_tiers')->nullOnDelete();
            $table->string('image_url', 500)->nullable()->after('description');
            $table->unsignedInteger('reservation_ttl_minutes')->default(60)->after('points_cost');
            $table->unsignedInteger('daily_limit_per_member')->nullable()->after('reservation_ttl_minutes');
        });

        Schema::create('reward_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('loyalty_reward_id')->constrained('loyalty_rewards')->restrictOnDelete();
            $table->foreignId('store_location_id')->constrained('store_locations')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('points_cost');
            $table->string('idempotency_key', 100);
            $table->string('pickup_code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('point_transaction_id')->nullable()->constrained('member_point_transactions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['member_id', 'idempotency_key']);
            $table->index(['status', 'expires_at']);
            $table->index(['store_location_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_reservations');
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reward_category_id');
            $table->dropConstrainedForeignId('minimum_tier_id');
            $table->dropColumn(['image_url', 'reservation_ttl_minutes', 'daily_limit_per_member']);
        });
        Schema::dropIfExists('member_otp_challenges');
        Schema::dropIfExists('member_accounts');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('reward_categories');
        Schema::dropIfExists('loyalty_tier_perks');
        Schema::dropIfExists('loyalty_tiers');
    }
};
