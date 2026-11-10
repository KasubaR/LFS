<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One row per membership number, naming the PERSON who owns it.
 *
 * memberships.membership_number cannot be UNIQUE: a renewal is a new
 * memberships row that deliberately carries the same number forward. This
 * table is the missing rule: a number belongs to exactly one user, enforced by
 * the primary key. The key is the number with everything except letters and
 * digits removed and upper-cased, so LFS14149, LFS-14149 and "lfs 14149" are
 * the same number (that is how logins and the partner API already match them).
 *
 * Existing data is backfilled oldest membership first. If two different people
 * already share a number, the earliest keeps it, the clash is logged, and the
 * migration still completes: run `php artisan membership:check-number-clashes`
 * BEFORE migrating to see them and fix the data first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_number_owners', function (Blueprint $table): void {
            $table->string('number_key', 40)->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        $seen = [];
        $clashes = [];

        DB::table('memberships')
            ->whereNotNull('membership_number')
            ->where('membership_number', '!=', '')
            ->orderBy('created_at')
            ->orderBy('id')
            ->select(['membership_number', 'user_id'])
            ->chunk(500, function ($rows) use (&$seen, &$clashes): void {
                $insert = [];
                foreach ($rows as $row) {
                    $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $row->membership_number) ?? '');
                    if ($key === '') {
                        continue;
                    }
                    if (isset($seen[$key])) {
                        if ($seen[$key] !== (int) $row->user_id) {
                            $clashes[] = "{$row->membership_number} (user {$row->user_id}) clashes with user {$seen[$key]}";
                        }

                        continue;
                    }
                    $seen[$key] = (int) $row->user_id;
                    $insert[] = ['number_key' => $key, 'user_id' => (int) $row->user_id, 'created_at' => now(), 'updated_at' => now()];
                }
                if ($insert !== []) {
                    DB::table('membership_number_owners')->insertOrIgnore($insert);
                }
            });

        if ($clashes !== []) {
            Log::warning('[membership_number_owners] existing membership numbers shared by different people; the earliest holder keeps the number', [
                'clashes' => $clashes,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_number_owners');
    }
};
