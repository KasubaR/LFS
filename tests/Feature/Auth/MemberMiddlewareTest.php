<?php

namespace Tests\Feature\Auth;

use App\Enums\MembershipPaymentStatus;
use App\Models\Membership;
use App\Models\MembershipPayment;
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
                'password' => 'MyNewPass1!',
                'password_confirmation' => 'MyNewPass1!',
            ])
            ->assertRedirect('/account');

        $this->actingAs($user->fresh())
            ->get('/account')
            ->assertOk()
            ->assertSee('21684', false);
    }

    public function test_suspended_member_with_outstanding_balance_is_redirected_to_balance_page(): void
    {
        $user = User::factory()->create([
            'email' => 'balance@example.com',
            'must_change_password' => false,
        ]);

        $plan = MembershipPlan::query()->first();

        // Suspended (grace period ended unpaid) — not merely PartiallyPaid —
        // is what gates access now; a PartiallyPaid-but-Active membership
        // (still within the grace period) gets full access instead.
        $membership = Membership::query()->create([
            'id' => '00000000-0000-4000-8000-000000000020',
            'user_id' => $user->id,
            'membership_number' => '13657',
            'status' => 'suspended',
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
            'approved_by' => 'system:import',
            'joined_at' => now(),
            'start_date' => now()->toDateString(),
            'expiry_date' => now()->addMonths(6)->toDateString(),
        ]);

        MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $plan->id,
            'amount' => 1000,
            'amount_paid' => 900,
            'currency' => 'ZMW',
            'payment_gateway' => 'import',
            'status' => MembershipPaymentStatus::PartiallyPaid,
        ]);

        // Any gated account page redirects to the balance page while owed.
        $this->actingAs($user)->get('/account')->assertRedirect('/account/balance');
        $this->actingAs($user)->get('/account/payments')->assertRedirect('/account/balance');

        $this->actingAs($user)->get('/account/balance')
            ->assertOk()
            ->assertSee('K100.00', false);
    }

    public function test_partially_paid_active_member_gets_full_access_during_grace_period(): void
    {
        $user = User::factory()->create([
            'email' => 'grace@example.com',
            'must_change_password' => false,
        ]);

        $plan = MembershipPlan::query()->first();

        $membership = Membership::query()->create([
            'id' => '00000000-0000-4000-8000-000000000021',
            'user_id' => $user->id,
            'membership_number' => '13658',
            'status' => 'active',
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
            'approved_by' => 'system:lenco',
            'joined_at' => now(),
            'start_date' => now()->toDateString(),
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'grace_period_ends_at' => now()->addMonths(2)->toDateString(),
        ]);

        MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $plan->id,
            'amount' => 1000,
            'amount_paid' => 250,
            'currency' => 'ZMW',
            'payment_gateway' => 'lenco',
            'status' => MembershipPaymentStatus::PartiallyPaid,
        ]);

        $this->actingAs($user)->get('/account')->assertOk();
        $this->actingAs($user)->get('/account/payments')->assertOk();
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
