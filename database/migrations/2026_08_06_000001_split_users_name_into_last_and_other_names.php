<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('last_name', 120)->nullable()->after('name');
            $table->string('other_names', 120)->nullable()->after('last_name');
        });

        // Backfill from the existing single `name` column: last space-separated
        // word becomes the surname, everything before it becomes other names.
        // A single-word name has no other_names.
        DB::table('users')->select('id', 'name')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $parts = preg_split('/\s+/', trim((string) $user->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $lastName = $parts === [] ? '' : array_pop($parts);
                $otherNames = $parts === [] ? null : implode(' ', $parts);

                DB::table('users')->where('id', $user->id)->update([
                    'last_name' => $lastName,
                    'other_names' => $otherNames,
                ]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->after('id')->default('');
        });

        DB::table('users')->select('id', 'last_name', 'other_names')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $name = trim(trim((string) $user->other_names).' '.trim((string) $user->last_name));

                DB::table('users')->where('id', $user->id)->update(['name' => $name]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_name', 'other_names']);
        });
    }
};
