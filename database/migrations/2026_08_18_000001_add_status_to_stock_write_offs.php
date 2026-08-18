<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('stock_write_offs')) {
            return;
        }

        Schema::table('stock_write_offs', function (Blueprint $t) {
            if (! Schema::hasColumn('stock_write_offs', 'status')) {
                // draft = editable, stock not touched yet
                // submitted = FIFO consumed, locked
                $t->string('status', 20)->default('submitted')->after('note')->index();
            }
            if (! Schema::hasColumn('stock_write_offs', 'submitted_at')) {
                $t->timestamp('submitted_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('stock_write_offs', 'submitted_by')) {
                $t->unsignedBigInteger('submitted_by')->nullable()->index()->after('submitted_at');
            }
        });

        // Existing rows already consumed stock → treat as submitted.
        DB::table('stock_write_offs')
            ->whereNull('submitted_at')
            ->update([
                'status' => 'submitted',
                'submitted_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)'),
                'submitted_by' => DB::raw('user_id'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_write_offs')) {
            return;
        }

        Schema::table('stock_write_offs', function (Blueprint $t) {
            if (Schema::hasColumn('stock_write_offs', 'submitted_by')) {
                $t->dropColumn('submitted_by');
            }
            if (Schema::hasColumn('stock_write_offs', 'submitted_at')) {
                $t->dropColumn('submitted_at');
            }
            if (Schema::hasColumn('stock_write_offs', 'status')) {
                $t->dropColumn('status');
            }
        });
    }
};
