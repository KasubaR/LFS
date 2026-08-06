<?php

namespace Tests\Feature\Auth;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    public function test_imported_user_is_redirected_to_password_change_after_login(): void
    {
        $user = User::factory()->create([
            'email' => 'imported@example.com',
            'password' => 'temp-pass-123',
            'must_change_password' => true,
        ]);

        Membership::query()->create([
            'id' => '00000000-0000-4000-8000-000000000001',
            'user_id' => $user->id,
            'membership_number' => '13239',
            'status' => 'active',
            'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'approved',
            'approved_by' => 'system:import',
            'joined_at' => now(),
            'start_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $response = $this->post('/login', [
            'email' => 'imported@example.com',
            'password' => 'temp-pass-123',
        ]);

        $response->assertRedirect('/password/change');
    }

    public function test_website_registrant_is_never_redirected_to_password_change(): void
    {
        $user = User::factory()->create([
            'email' => 'selfreg@example.com',
            'password' => 'password123',
            'must_change_password' => false,
        ]);

        $response = $this->post('/login', [
            'email' => 'selfreg@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/membership/apply');
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_imported_user_can_change_password_and_reach_account(): void
    {
        $user = User::factory()->create([
            'email' => 'imported2@example.com',
            'password' => 'temp-pass-123',
            'must_change_password' => true,
        ]);

        Membership::query()->create([
            'id' => '00000000-0000-4000-8000-000000000002',
            'user_id' => $user->id,
            'membership_number' => '13240',
            'status' => 'active',
            'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'approved',
            'approved_by' => 'system:import',
            'joined_at' => now(),
            'start_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->actingAs($user);

        $response = $this->post('/password/change', [
            'current_password' => 'temp-pass-123',
            'password' => 'NewSecure-Pass1',
            'password_confirmation' => 'NewSecure-Pass1',
        ]);

        $response->assertRedirect('/account');
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('NewSecure-Pass1', $user->fresh()->password));
    }

    public function test_password_change_requires_correct_current_password(): void
    {
        $user = User::factory()->create([
            'email' => 'imported3@example.com',
            'password' => 'temp-pass-123',
            'must_change_password' => true,
        ]);

        $this->actingAs($user);

        $response = $this->post('/password/change', [
            'current_password' => 'wrong-password',
            'password' => 'new-secure-pass',
            'password_confirmation' => 'new-secure-pass',
        ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('temp-pass-123', $user->fresh()->password));
    }

    public function test_password_change_rejects_reusing_the_current_password(): void
    {
        $user = User::factory()->create([
            'email' => 'imported4@example.com',
            'password' => 'temp-pass-123',
            'must_change_password' => true,
        ]);

        $this->actingAs($user);

        $response = $this->post('/password/change', [
            'current_password' => 'temp-pass-123',
            'password' => 'temp-pass-123',
            'password_confirmation' => 'temp-pass-123',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_password_change_rejects_the_shared_temp_password_as_new_password(): void
    {
        $sharedTempPassword = config('member_import.temp_password');

        $user = User::factory()->create([
            'email' => 'imported5@example.com',
            // Current password differs from the shared temp password so this
            // only exercises the NotSharedTempPassword rule, not `different`.
            'password' => 'some-other-current-pass',
            'must_change_password' => true,
        ]);

        $this->actingAs($user);

        $response = $this->post('/password/change', [
            'current_password' => 'some-other-current-pass',
            'password' => $sharedTempPassword,
            'password_confirmation' => $sharedTempPassword,
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_account_is_blocked_until_password_is_changed(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
        ]);

        Membership::query()->create([
            'id' => '00000000-0000-4000-8000-000000000003',
            'user_id' => $user->id,
            'membership_number' => '13241',
            'status' => 'active',
            'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'approved',
            'approved_by' => 'system:import',
            'joined_at' => now(),
            'start_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $response = $this->actingAs($user)->get('/account');

        $response->assertRedirect('/password/change');
    }
}
