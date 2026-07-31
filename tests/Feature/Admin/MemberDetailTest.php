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
            'name' => 'Detail Test User',
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
}
