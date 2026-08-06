<?php

namespace Tests\Feature\Auth;

use App\Enums\TShirtSize;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Satellite;
use App\Models\User;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers EnsureProfileComplete: imported members can land with gaps the
 * spreadsheet didn't have (most commonly a satellite the sheet named didn't
 * match), and must fill them in before using the rest of /account.
 */
class ProfileCompletionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    private function memberWithMembership(User $user): User
    {
        Membership::query()->create([
            'id' => '00000000-0000-4000-8000-000000000099',
            'user_id' => $user->id,
            'membership_number' => 'GATE-001',
            'status' => 'active',
            'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'approved',
            'approved_by' => 'system:test',
            'joined_at' => now(),
            'start_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        return $user;
    }

    public function test_incomplete_profile_is_redirected_to_personal_details(): void
    {
        $user = $this->memberWithMembership(
            User::factory()->incompleteProfile()->create()
        );

        $this->actingAs($user)
            ->get('/account')
            ->assertRedirect(route('account.settings.personal'));
    }

    public function test_missing_satellite_alone_is_enough_to_trigger_the_gate(): void
    {
        // The common real-world case: the import row's satellite name didn't
        // match a known satellite, so every other field is fine but
        // satellite_id is null.
        $user = $this->memberWithMembership(
            User::factory()->create(['satellite_id' => null])
        );

        $this->actingAs($user)
            ->get('/account/orders')
            ->assertRedirect(route('account.settings.personal'));
    }

    public function test_complete_profile_is_not_redirected(): void
    {
        $user = $this->memberWithMembership(User::factory()->create());

        $this->actingAs($user)
            ->get('/account')
            ->assertOk();
    }

    public function test_personal_details_page_itself_is_reachable_despite_incomplete_profile(): void
    {
        // Otherwise a member could never reach the one page that lets them
        // fix the problem.
        $user = $this->memberWithMembership(
            User::factory()->incompleteProfile()->create()
        );

        $this->actingAs($user)
            ->get('/account/settings/personal')
            ->assertOk();
    }

    public function test_submitting_the_missing_fields_clears_the_gate(): void
    {
        $user = $this->memberWithMembership(
            User::factory()->incompleteProfile()->create()
        );
        $satellite = Satellite::query()->first();

        $this->actingAs($user)->post('/account/settings/personal', [
            'last_name' => $user->last_name,
            'other_names' => 'Given',
            'phone' => '0977000111',
            'gender' => 'female',
            'nationality' => 'Zambian',
            'satellite_id' => $satellite->id,
            'town' => 'Lusaka',
            't_shirt_size' => TShirtSize::M,
        ])->assertRedirect(route('account.settings.personal'));

        $this->assertTrue($user->fresh()->hasCompleteProfile());

        $this->actingAs($user->fresh())
            ->get('/account')
            ->assertOk();
    }

    public function test_logout_is_not_blocked_by_an_incomplete_profile(): void
    {
        $user = $this->memberWithMembership(
            User::factory()->incompleteProfile()->create()
        );

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/');
    }
}
