<?php

namespace App\Console\Commands;

use App\Enums\MembershipPaymentStatus;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Services\MembershipService;
use Illuminate\Console\Command;

/**
 * Grace period enforcement: any Active membership whose grace_period_ends_at
 * (30 April of its registration year — only ever set for members who joined/
 * renewed within the normal Jan 1 - Apr 30 window, see
 * MembershipService::computeMembershipYearDates()) has passed without the
 * annual fee being paid in full gets Suspended.
 */
class SuspendUnpaidMembershipsCommand extends Command
{
    protected $signature = 'membership:suspend-unpaid';

    protected $description = 'Suspend active memberships whose grace period ended (30 April) without full payment';

    public function handle(MembershipService $membershipService): int
    {
        $memberships = Membership::query()
            ->where('status', MembershipStatus::Active)
            ->whereNotNull('grace_period_ends_at')
            ->where('grace_period_ends_at', '<', now()->toDateString())
            ->with('payments')
            ->get();

        $count = 0;
        foreach ($memberships as $membership) {
            $payment = $membership->payments->sortByDesc('created_at')->first();

            $stillOwes = $payment && (
                $payment->status === MembershipPaymentStatus::PartiallyPaid
                || $payment->status === MembershipPaymentStatus::Pending
                || ($payment->status === MembershipPaymentStatus::Failed && (float) $payment->amount_paid < (float) $payment->amount)
            );

            if (! $stillOwes) {
                continue;
            }

            $membershipService->suspend($membership->id);
            $count++;
        }

        $this->info("Suspended {$count} membership(s) for non-payment.");

        return self::SUCCESS;
    }
}
