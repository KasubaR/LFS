<?php

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Services\MembershipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imported members who never paid their balance were first stored as Expired
 * with a payment marked "paid" for just the instalment. This moves them to
 * Suspended with the real balance owed, so they clear it on /account/balance.
 */
class FixExpiredImportBalancesCommand extends Command
{
    protected $signature = 'membership:fix-expired-balances {--dry-run : Show what would change, save nothing}';

    protected $description = 'Turn expired imported members into suspended members owing their correct balance';

    public function handle(MembershipService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            DB::beginTransaction();
        }

        $rows = [];
        $skipped = 0;

        try {
            $memberships = Membership::query()->where('status', MembershipStatus::Expired)->get();
            foreach ($memberships as $membership) {
                $payment = MembershipPayment::query()->where('membership_id', $membership->id)->orderByDesc('id')->first();
                if (! $payment || $payment->payment_gateway !== 'import') {
                    $skipped++;

                    continue;
                }

                $result = $service->suspendForUnpaidBalance($membership->id, 'system:fix-expired-balances', 'Corrected: imported instalment with balance unpaid');
                if ($result === null) {
                    $skipped++;

                    continue;
                }

                $rows[] = [$membership->membership_number, $membership->joined_at?->toDateString(), $result['paid'], $result['feeDue'], $result['balance']];
            }
        } finally {
            if ($dryRun) {
                DB::rollBack();
                $this->warn('Dry run: nothing was saved.');
            }
        }

        $this->table(['Number', 'Registered', 'Paid', 'Fee due', 'Balance'], $rows);
        $this->info(count($rows).' member(s) set to suspended with balance; '.$skipped.' skipped.');

        return self::SUCCESS;
    }
}
