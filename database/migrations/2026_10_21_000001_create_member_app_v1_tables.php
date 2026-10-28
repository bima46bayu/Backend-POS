<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member app API v1 (ARCHITECTURE.md). Purely additive: new tables and
 * nullable/defaulted columns only. The point ledger is not touched.
 *
 *  - promotions: badge + capacity shown on the Home carousel
 *  - promotion_dismissals: "dismissed stays dismissed" per member
 *  - seasons: stats period for the History banner
 *  - loyalty_tiers.multiplier: shown as a perk; earn-side use is a later step
 *  - loyalty_tier_perks.icon: icon name for the Membership perk list
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            if (! Schema::hasColumn('promotions', 'badge')) {
                $table->string('badge', 40)->nullable()->after('title');
            }
            if (! Schema::hasColumn('promotions', 'capacity')) {
                $table->unsignedInteger('capacity')->nullable()->after('ends_at');
            }
        });

        if (! Schema::hasTable('promotion_dismissals')) {
            Schema::create('promotion_dismissals', function (Blueprint $table) {
                $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
                $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
                $table->timestamp('dismissed_at');
                $table->primary(['member_id', 'promotion_id']);
            });
        }

        if (! Schema::hasTable('seasons')) {
            Schema::create('seasons', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                // DATETIME, not TIMESTAMP: MySQL gives a second NOT NULL
                // TIMESTAMP column an invalid zero default under strict mode.
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['is_active', 'starts_at', 'ends_at']);
            });
        }

        Schema::table('loyalty_tiers', function (Blueprint $table) {
            if (! Schema::hasColumn('loyalty_tiers', 'multiplier')) {
                $table->decimal('multiplier', 4, 2)->default(1.00)->after('minimum_lifetime_points');
            }
        });

        Schema::table('loyalty_tier_perks', function (Blueprint $table) {
            if (! Schema::hasColumn('loyalty_tier_perks', 'icon')) {
                $table->string('icon', 40)->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_tier_perks', fn (Blueprint $t) => $t->dropColumn('icon'));
        Schema::table('loyalty_tiers', fn (Blueprint $t) => $t->dropColumn('multiplier'));
        Schema::dropIfExists('seasons');
        Schema::dropIfExists('promotion_dismissals');
        Schema::table('promotions', fn (Blueprint $t) => $t->dropColumn(['badge', 'capacity']));
    }
};
