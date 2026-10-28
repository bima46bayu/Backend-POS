<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email + password sign-up for the member app.
 *
 * Accounts created from the app have an email and (until phone OTP ships)
 * no phone, so `phone` becomes nullable. Its unique index stays: NULLs do
 * not collide. OTP-registered accounts keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('member_accounts', 'email')) {
            Schema::table('member_accounts', function (Blueprint $table) {
                $table->string('email', 120)->nullable()->unique()->after('phone');
            });
        }

        Schema::table('member_accounts', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('member_accounts', function (Blueprint $table) {
            $table->string('phone', 30)->nullable(false)->change();
        });

        if (Schema::hasColumn('member_accounts', 'email')) {
            Schema::table('member_accounts', function (Blueprint $table) {
                $table->dropUnique(['email']);
                $table->dropColumn('email');
            });
        }
    }
};
