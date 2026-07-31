<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('memberships', 'renewal_deadline')) {
            return;
        }

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropIndex(['renewal_deadline']);
            $table->dropColumn('renewal_deadline');
        });

        if (! Schema::hasColumn('memberships', 'period_end')) {
            return;
        }

        Schema::table('memberships', function (Blueprint $table) {
            $table->index('period_end');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            if (Schema::hasColumn('memberships', 'period_end')) {
                $table->dropIndex(['period_end']);
            }

            if (! Schema::hasColumn('memberships', 'renewal_deadline')) {
                $table->date('renewal_deadline')->nullable()->after('period_end');
                $table->index('renewal_deadline');
            }
        });
    }
};
