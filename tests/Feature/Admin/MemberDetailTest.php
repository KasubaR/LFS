<?php

namespace Tests\Feature\Admin;

use App\Enums\MembershipHistoryEvent;
use App\Models\Membership;
use App\Models\MembershipHistory;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\MembershipService;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class MemberDetailTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    public function test_member_detail_page_renders_for_existing_member(): void
    {
        $user = User::factory()->create([
            'last_name' => 'User',
            'other_names' => 'Detail Test',
            'email' => 'detail@example.com',
        ]);

        Membership::query()->create([
            'id' => '00000000-0000-4000-8000-000000000020',
            'user_id' => $user->id,
            'membership_number' => '30001',
            'status' => 'active',
            'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'approved',
            'approved_by' => 'system:import',
            'joined_at' => now(),
            'start_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $response = $this->actingAsAdmin()->get('/admin/members/'.$user->id);

        $response->assertOk();
        $response->assertSee('Detail Test User', false);
        $response->assertSee('detail@example.com', false);
        $response->assertSee('30001', false);
    }

    public function test_member_detail_page_404s_for_missing_member(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/members/999999');

        $response->assertNotFound();
    }

    public function test_admin_can_cancel_a_membership(): void
    {
        $user = User::factory()->create();

        Membership::query()->create([
            'id' => '00000000-0000-4000-8000-000000000021',
            'user_id' => $user->id,
            'membership_number' => '30002',
            'status' => 'active',
            'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'approved',
            'approved_by' => 'system:import',
            'joined_at' => now(),
            'start_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $response = $this->actingAsAdmin()->post(
            '/admin/members/'.$user->id.'/memberships/00000000-0000-4000-8000-000000000021/cancel',
            ['reason' => 'Test cancellation']
        );

        $response->assertRedirect('/admin/members/'.$user->id);

        $membership = Membership::query()->find('00000000-0000-4000-8000-000000000021');
        $this->assertSame('cancelled', $membership->status);

        $this->assertTrue(
            MembershipHistory::query()
                ->where('membership_id', $membership->id)
                ->where('event', MembershipHistoryEvent::Cancelled)
                ->exists()
        );

        $service = app(MembershipService::class);
        $this->assertFalse($service->userHasOpenMembership($user->id));
    }

    public function test_admin_cannot_cancel_membership_belonging_to_another_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $planId = MembershipPlan::query()->first()->id;

        Membership::query()->create([
            'id' => '00000000-0000-4000-8000-000000000030',
            'user_id' => $userA->id,
            'membership_number' => '30010',
            'status' => 'active',
            'current_plan_id' => $planId,
            'approval_status' => 'approved',
            'joined_at' => now(),
            'start_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        Membership::query()->create([
            'id' => '00000000-0000-4000-8000-000000000031',
            'user_id' => $userB->id,
            'membership_number' => '30011',
            'status' => 'active',
            'current_plan_id' => $planId,
            'approval_status' => 'approved',
            'joined_at' => now(),
            'start_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $response = $this->actingAsAdmin()->post(
            '/admin/members/'.$userA->id.'/memberships/00000000-0000-4000-8000-000000000031/cancel',
            ['reason' => 'Cross-user cancel attempt']
        );

        $response->assertNotFound();
        $this->assertSame('active', Membership::query()->find('00000000-0000-4000-8000-000000000031')->status);
    }

    public function test_admin_can_cancel_an_expired_membership(): void
    {
        $user = User::factory()->create();

        Membership::query()->create([
            'id' => '00000000-0000-4000-8000-000000000032',
            'user_id' => $user->id,
            'membership_number' => '30012',
            'status' => 'expired',
            'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'approved',
            'joined_at' => now()->subYear(),
            'start_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
        ]);

        // Regression test: the detail page's cancel form used to be hidden
        // for Expired rows even though MembershipStatus::TRANSITIONS permits
        // Expired -> Cancelled — the POST below worked, but there was no way
        // to reach it from the UI. See $cancellableStatuses in show.blade.php.
        $this->actingAsAdmin()->get('/admin/members/'.$user->id)
            ->assertOk()
            ->assertSee('/admin/members/'.$user->id.'/memberships/00000000-0000-4000-8000-000000000032/cancel', false);

        $this->actingAsAdmin()->post(
            '/admin/members/'.$user->id.'/memberships/00000000-0000-4000-8000-000000000032/cancel',
            ['reason' => 'Expired cleanup']
        )->assertRedirect('/admin/members/'.$user->id);

        $this->assertSame('cancelled', Membership::query()->find('00000000-0000-4000-8000-000000000032')->status);
    }

    public function test_suspended_member_shows_correctly_in_admin_list_and_detail(): void
    {
        $user = User::factory()->create([
            'last_name' => 'Suspended',
            'other_names' => 'Test',
            'email' => 'suspended-admin-view@example.com',
        ]);

        Membership::query()->create([
            'id' => '00000000-0000-4000-8000-000000000033',
            'user_id' => $user->id,
            'membership_number' => '30013',
            'status' => 'suspended',
            'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'approved',
            'joined_at' => now()->subMonths(4),
            'start_date' => now()->subMonths(4)->toDateString(),
            'expiry_date' => now()->addMonths(8)->toDateString(),
            'grace_period_ends_at' => now()->subMonth()->toDateString(),
        ]);

        // The list view has no membership-number column — just confirm the
        // suspended row shows up under the filter with the right status pill.
        $this->actingAsAdmin()->get('/admin/members/list?status=suspended')
            ->assertOk()
            ->assertSee('suspended', false)
            ->assertSee('suspended-admin-view@example.com', false);

        $this->actingAsAdmin()->get('/admin/members/'.$user->id)
            ->assertOk()
            ->assertSee('suspended', false);
    }
}
