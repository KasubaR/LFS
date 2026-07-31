<?php

namespace Tests\Feature\Auth;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MemberMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    public function test_imported_user_full_onboarding_flow_reaches_account(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'flow@example.com',
            'password' => 'temp-pass-123',
            'must_change_password' => true,
        ]);

        Membership::query()->create([
            'id' => '00000000-0000-4000-8000-000000000010',
            'user_id' => $user->id,
            'membership_number' => '21684',
            'status' => 'active',
            'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'approved',
            'approved_by' => 'system:import',
            'joined_at' => now(),
            'start_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->post('/login', [
            'email' => 'flow@example.com',
            'password' => 'temp-pass-123',
        ])->assertRedirect('/email/verify');

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)
            ->get($verificationUrl)
            ->assertRedirect('/password/change');

        $this->actingAs($user->fresh())
            ->post('/password/change', [
                'current_password' => 'temp-pass-123',
                'password' => 'my-new-password',
                'password_confirmation' => 'my-new-password',
            ])
            ->assertRedirect('/account');

        $this->actingAs($user->fresh())
            ->get('/account')
            ->assertOk()
            ->assertSee('21684', false);
    }

    public function test_website_registrant_flow_skips_password_change(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'selfreg@example.com',
            'password' => 'password123',
            'must_change_password' => false,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)
            ->get($verificationUrl)
            ->assertRedirect('/membership/apply');

        $this->actingAs($user->fresh())
            ->get('/password/change')
            ->assertRedirect('/membership/apply');
    }
}
