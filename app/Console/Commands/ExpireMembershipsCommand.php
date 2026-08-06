<?php

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Services\MembershipService;
use Illuminate\Console\Command;

class ExpireMembershipsCommand extends Command
{
    protected $signature = 'membership:expire';

    protected $description = 'Mark active (or suspended) memberships past their expiry date as expired';

    public function handle(MembershipService $membershipService): int
    {
        // Suspended memberships whose year ends (31 Dec) still unpaid also
        // roll to Expired, so they re-enter the normal renewal flow in
        // January instead of staying suspended indefinitely.
        $membershipIds = Membership::query()
            ->whereIn('status', [MembershipStatus::Active, MembershipStatus::Suspended])
            ->where('expiry_date', '<', now()->toDateString())
            ->pluck('id');

        $count = 0;
        foreach ($membershipIds as $membershipId) {
            $membershipService->expire($membershipId, 'Membership period ended');
            $count++;
        }

        $this->info("Expired {$count} membership(s).");

        return self::SUCCESS;
    }
}
