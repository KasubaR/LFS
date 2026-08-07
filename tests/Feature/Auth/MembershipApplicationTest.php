<?php

namespace Tests\Feature\Auth;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Satellite;
use App\Models\User;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    public function test_verified_user_can_view_membership_apply_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/membership/apply');

        $response->assertOk();
        $response->assertSee('Annual Membership', false);
        $response->assertSee('Initial payment', false);
        $response->assertSee('K250 initial payment', false);
        $response->assertSee('K500 initial payment', false);
        $response->assertSee('Full annual payment', false);
        $response->assertDontSee('Quarterly', false);
        $response->assertDontSee('Semi Annual', false);
    }

    public function test_verified_user_can_submit_membership_application(): void
    {
        $user = User::factory()->create();
        $satellite = Satellite::query()->first();
        $plan = MembershipPlan::query()->first();

        $response = $this->actingAs($user)->post('/membership/apply', [
            'satellite_id' => $satellite->id,
            'plan_id' => $plan->id,
        ]);

        $response->assertRedirect('/account');

        $user->refresh();
        $this->assertSame($satellite->id, $user->satellite_id);

        $membership = Membership::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame('draft', $membership->status);
        // No membership number until payment is confirmed — the account page
        // shows "Pending" in the meantime.
        $this->assertNull($membership->membership_number);
    }

    public function test_account_redirects_to_apply_when_user_has_no_membership(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/account');

        $response->assertRedirect('/membership/apply');
    }

    public function test_apply_redirects_to_account_when_membership_already_exists(): void
    {
        $user = User::factory()->create();
        $satellite = Satellite::query()->first();
        $plan = MembershipPlan::query()->first();

        $this->actingAs($user)->post('/membership/apply', [
            'satellite_id' => $satellite->id,
            'plan_id' => $plan->id,
        ]);

        $response = $this->actingAs($user)->get('/membership/apply');

        $response->assertRedirect('/account');
    }
}
