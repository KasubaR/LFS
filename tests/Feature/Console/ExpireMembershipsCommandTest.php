<?php

namespace Tests\Feature\Console;

use App\Enums\MembershipHistoryEvent;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipHistory;
use App\Models\MembershipPlan;
use App\Models\User;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpireMembershipsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    public function test_command_expires_active_memberships_past_expiry_date(): void
    {
        $plan = MembershipPlan::query()->first();
        $user = User::factory()->create();

        $membership = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-000010',
            'status' => MembershipStatus::Active,
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
            'start_date' => now()->subMonths(13)->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
            'joined_at' => now()->subMonths(13),
        ]);

        $stillActiveUser = User::factory()->create();
        $stillActive = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $stillActiveUser->id,
            'membership_number' => 'LFS-000011',
            'status' => MembershipStatus::Active,
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
            'start_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addMonths(11)->toDateString(),
            'joined_at' => now()->subMonth(),
        ]);

        $this->artisan('membership:expire')->assertSuccessful();

        $this->assertSame(MembershipStatus::Expired, $membership->fresh()->status);
        $this->assertSame(MembershipStatus::Active, $stillActive->fresh()->status);

        $this->assertTrue(
            MembershipHistory::query()
                ->where('membership_id', $membership->id)
                ->where('event', MembershipHistoryEvent::Expired)
                ->exists()
        );
    }

    public function test_command_also_expires_suspended_memberships_past_expiry_date(): void
    {
        $plan = MembershipPlan::query()->first();
        $user = User::factory()->create();

        // Suspended (grace period ended unpaid) but the membership year is
        // also over now — should still roll to Expired so the member
        // re-enters the normal renewal flow in January.
        $membership = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-000012',
            'status' => MembershipStatus::Suspended,
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
            'start_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
            'grace_period_ends_at' => now()->subMonths(8)->toDateString(),
            'joined_at' => now()->subYear(),
        ]);

        $this->artisan('membership:expire')->assertSuccessful();

        $this->assertSame(MembershipStatus::Expired, $membership->fresh()->status);
    }
}
