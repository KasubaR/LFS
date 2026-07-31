<?php

use App\Support\Uuid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hard-to-guess scan target for digital membership cards.
     * Allocated when a membership number is assigned (payment / import).
     */
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->uuid('public_token')->nullable()->unique()->after('membership_number');
        });

        $rows = DB::table('memberships')
            ->whereNotNull('membership_number')
            ->whereNull('public_token')
            ->select('id')
            ->get();

        foreach ($rows as $row) {
            DB::table('memberships')
                ->where('id', $row->id)
                ->update(['public_token' => Uuid::v4()]);
        }
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
