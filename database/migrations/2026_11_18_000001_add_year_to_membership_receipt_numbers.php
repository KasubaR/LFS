<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Receipt numbers now carry the year: LFS-RCT-000448 becomes LFS-RCT-2600448
 * (YY + 5-digit sequence). Existing receipts take the year they were paid
 * (falling back to when the payment row was created). The sequence counter is
 * unchanged, so numbers never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('membership.receipt_number_prefix', 'LFS-RCT');

        DB::table('membership_payments')
            ->where('receipt_number', 'like', $prefix.'-%')
            ->orderBy('id')
            ->each(function ($p) use ($prefix): void {
                if (! preg_match('/^'.preg_quote($prefix, '/').'-(\d{6})$/', (string) $p->receipt_number, $m)) {
                    return; // already new format or something custom
                }
                $year = date('y', strtotime((string) ($p->paid_at ?? $p->created_at ?? 'now')));
                DB::table('membership_payments')->where('id', $p->id)->update([
                    'receipt_number' => sprintf('%s-%s%05d', $prefix, $year, (int) $m[1]),
                ]);
            });
    }

    public function down(): void
    {
        // Not reversible: the original year-less numbers were issued to members.
    }
};
