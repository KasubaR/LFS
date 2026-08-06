<?php

namespace Tests\Feature\Auth;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use Database\Seeders\MembershipPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Sign In', false);
        $response->assertSee('lfs-form', false);
    }

    public function test_users_can_authenticate_with_verified_email(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'password' => 'password123',
        ]);

        $response = $this->post('/login', [
            'email' => 'member@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/membership/apply');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->first_login);
    }

    public function test_unverified_users_are_redirected_to_email_verify(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'unverified@example.com',
            'password' => 'password123',
        ]);

        $this->post('/login', [
            'email' => 'unverified@example.com',
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response = $this->get('/account');
        $response->assertRedirect('/email/verify');
    }

    public function test_expired_temp_password_login_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'stale-temp@example.com',
            'password' => 'TempPass123',
            'must_change_password' => true,
            'temp_password_expires_at' => now()->subDay(),
        ]);

        $response = $this->post('/login', [
            'email' => 'stale-temp@example.com',
            'password' => 'TempPass123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_unexpired_temp_password_login_still_works(): void
    {
        $user = User::factory()->create([
            'email' => 'fresh-temp@example.com',
            'password' => 'TempPass123',
            'must_change_password' => true,
            'temp_password_expires_at' => now()->addDay(),
        ]);

        $response = $this->post('/login', [
            'email' => 'fresh-temp@example.com',
            'password' => 'TempPass123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/password/change');
    }

    private function createMembership(User $user, string $membershipNumber): Membership
    {
        $this->seed(MembershipPlanSeeder::class);
        $plan = MembershipPlan::query()->first();

        return Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => $membershipNumber,
            'status' => MembershipStatus::Active,
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
            'start_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addMonths(5)->toDateString(),
        ]);
    }

    public function test_users_can_authenticate_with_membership_number(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->createMembership($user, 'LFS-000042');

        $response = $this->post('/login', [
            'email' => 'LFS-000042',
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/account');
    }

    public function test_membership_number_login_is_case_insensitive(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->createMembership($user, 'LFS-000042');

        $response = $this->post('/login', [
            'email' => 'lfs-000042',
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_authenticate_with_a_backfilled_undashed_membership_number(): void
    {
        // Legacy bulk-imported members have an undashed number like
        // "LFS14149" (see MemberImportService/member-import:backfill-lfs-prefix).
        $user = User::factory()->create(['password' => 'password123']);
        $this->createMembership($user, 'LFS14149');

        $response = $this->post('/login', [
            'email' => 'LFS14149',
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/account');
    }

    public function test_users_can_authenticate_typing_the_bare_number_without_the_lfs_prefix(): void
    {
        // Members shouldn't be locked out just because they type the number
        // as originally printed on their old card, before it was prefixed.
        $user = User::factory()->create(['password' => 'password123']);
        $this->createMembership($user, 'LFS14149');

        $response = $this->post('/login', [
            'email' => '14149',
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/account');
    }

    public function test_login_rejects_unknown_membership_number(): void
    {
        $response = $this->post('/login', [
            'email' => 'LFS-999999',
            'password' => 'whatever',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $response = $this->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }
}
