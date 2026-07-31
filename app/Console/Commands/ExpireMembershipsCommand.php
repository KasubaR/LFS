<?php

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Services\MembershipService;
use Illuminate\Console\Command;

class ExpireMembershipsCommand extends Command
{
    protected $signature = 'membership:expire';

    protected $description = 'Mark active memberships past their expiry date as expired';

    public function handle(MembershipService $membershipService): int
    {
        $membershipIds = Membership::query()
            ->where('status', MembershipStatus::Active)
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
