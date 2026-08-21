<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Satellite;
use App\Models\User;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class AdminRbacTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    public function test_login_with_email_and_password(): void
    {
        $admin = $this->makeAdminUser([
            'email' => 'rbac-login@lfs.test',
            'password' => 'secret-pass-1',
            'role' => AdminRole::Finance,
        ]);

        $response = $this->post($this->adminLoginPath(), [
            'email' => 'rbac-login@lfs.test',
            'password' => 'secret-pass-1',
        ]);

        $response->assertRedirect('/admin/dashboard');
        $this->assertTrue(session(config('admin.session_auth_key')));
        $this->assertSame($admin->id, session(config('admin.session_user_key')));
    }

    public function test_electoral_commission_login_requires_totp_setup(): void
    {
        $this->makeAdminUser([
            'email' => 'ec-login@lfs.test',
            'password' => 'secret-pass-1',
            'role' => AdminRole::ElectoralCommission,
        ]);

        $response = $this->post($this->adminLoginPath(), [
            'email' => 'ec-login@lfs.test',
            'password' => 'secret-pass-1',
        ]);

        $response->assertRedirect('/admin/'.config('admin.login_slug').'/2fa/setup');
    }

    public function test_election_observer_login_also_requires_totp_setup(): void
    {
        // Observer is read-only, but it's still an elections-facing admin
        // role — the brief's "administrators should use 2FA" isn't scoped
        // to write access only.
        $this->makeAdminUser([
            'email' => 'observer-login@lfs.test',
            'password' => 'secret-pass-1',
            'role' => AdminRole::ElectionObserver,
        ]);

        $response = $this->post($this->adminLoginPath(), [
            'email' => 'observer-login@lfs.test',
            'password' => 'secret-pass-1',
        ]);

        $response->assertRedirect('/admin/'.config('admin.login_slug').'/2fa/setup');
    }

    public function test_super_admin_can_reset_another_admins_totp_enrollment(): void
    {
        $ec = $this->makeAdminUser([
            'role' => AdminRole::ElectoralCommission,
            'email' => 'reset-target@lfs.test',
        ]);
        $ec->forceFill([
            'totp_secret' => 'SOMESECRETVALUE',
            'totp_confirmed_at' => now(),
        ])->save();

        $this->actingAsAdmin()
            ->post("/admin/users/{$ec->id}/reset-totp")
            ->assertRedirect('/admin/users');

        $ec->refresh();
        $this->assertNull($ec->totp_secret);
        $this->assertNull($ec->totp_confirmed_at);
        $this->assertFalse($ec->hasTotpEnabled());

        // Confirms the reset actually re-triggers setup on next login, not
        // just that the columns were cleared.
        $response = $this->post($this->adminLoginPath(), [
            'email' => 'reset-target@lfs.test',
            'password' => 'password',
        ]);
        $response->assertRedirect('/admin/'.config('admin.login_slug').'/2fa/setup');
    }

    public function test_finance_admin_cannot_reset_totp_for_others(): void
    {
        $finance = $this->makeAdminUser(['role' => AdminRole::Finance]);
        $target = $this->makeAdminUser(['role' => AdminRole::ElectoralCommission]);

        $this->actingAsAdmin($finance)
            ->post("/admin/users/{$target->id}/reset-totp")
            ->assertForbidden();
    }

    public function test_inactive_admin_cannot_login(): void
    {
        $this->makeAdminUser([
            'email' => 'inactive@lfs.test',
            'password' => 'secret-pass-1',
            'is_active' => false,
        ]);

        $response = $this->from($this->adminLoginPath())->post($this->adminLoginPath(), [
            'email' => 'inactive@lfs.test',
            'password' => 'secret-pass-1',
        ]);

        $response->assertRedirect($this->adminLoginPath());
        $this->assertNull(session(config('admin.session_user_key')));
    }

    public function test_super_admin_can_access_users_and_api_keys(): void
    {
        $this->actingAsAdmin()
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Admin Users');

        $this->actingAsAdmin()
            ->get('/admin/api-clients')
            ->assertOk()
            ->assertSee('API Keys');
    }

    public function test_finance_cannot_access_members(): void
    {
        $finance = $this->makeAdminUser([
            'role' => AdminRole::Finance,
            'name' => 'Finance User',
        ]);

        $this->actingAsAdmin($finance)
            ->get('/admin/members')
            ->assertForbidden();

        $this->actingAsAdmin($finance)
            ->get('/admin/orders')
            ->assertOk();
    }

    public function test_finance_cannot_access_promotions(): void
    {
        $finance = $this->makeAdminUser([
            'role' => AdminRole::Finance,
            'name' => 'Finance User',
        ]);

        $this->actingAsAdmin($finance)
            ->get('/admin/promotions')
            ->assertForbidden();
    }

    public function test_finance_sidebar_hides_members(): void
    {
        $finance = $this->makeAdminUser(['role' => AdminRole::Finance]);

        $html = $this->actingAsAdmin($finance)
            ->get('/admin/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('href="/admin/members"', $html);
        $this->assertStringContainsString('href="/admin/orders"', $html);
        $this->assertStringNotContainsString('href="/admin/users"', $html);
    }

    public function test_auditor_can_get_but_not_post(): void
    {
        $auditor = $this->makeAdminUser([
            'role' => AdminRole::ReadOnlyAuditor,
        ]);

        $this->actingAsAdmin($auditor)
            ->get('/admin/announcements')
            ->assertOk();

        $this->actingAsAdmin($auditor)
            ->post('/admin/announcements/create', [
                'title' => 'Nope',
                'body' => 'Should fail',
                'published_at' => now()->format('Y-m-d\TH:i'),
                'is_active' => '1',
            ])
            ->assertForbidden();
    }

    public function test_auditor_cannot_access_admin_users(): void
    {
        $auditor = $this->makeAdminUser(['role' => AdminRole::ReadOnlyAuditor]);

        $this->actingAsAdmin($auditor)
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_satellite_admin_only_sees_assigned_satellite_members(): void
    {
        $woodies = Satellite::query()->where('slug', 'woodies')->firstOrFail();
        $avondale = Satellite::query()->where('slug', 'avondale')->firstOrFail();

        $inScope = User::factory()->create([
            'last_name' => 'Member',
            'other_names' => 'Woodies',
            'email' => 'woodies-member@lfs.test',
            'satellite_id' => $woodies->id,
        ]);
        $outOfScope = User::factory()->create([
            'last_name' => 'Member',
            'other_names' => 'Avondale',
            'email' => 'avondale-member@lfs.test',
            'satellite_id' => $avondale->id,
        ]);

        $this->createActiveMembership($inScope);
        $this->createActiveMembership($outOfScope);

        $admin = $this->makeSatelliteAdmin([$woodies->id]);

        $list = $this->actingAsAdmin($admin)
            ->get('/admin/members/list')
            ->assertOk();

        $list->assertSee('Woodies Member', false);
        $list->assertDontSee('Avondale Member', false);

        $this->actingAsAdmin($admin)
            ->get('/admin/members/'.$inScope->id)
            ->assertOk();

        $this->actingAsAdmin($admin)
            ->get('/admin/members/'.$outOfScope->id)
            ->assertForbidden();
    }

    public function test_satellite_admin_cannot_import_members(): void
    {
        $woodies = Satellite::query()->where('slug', 'woodies')->firstOrFail();
        $admin = $this->makeSatelliteAdmin([$woodies->id]);

        $this->actingAsAdmin($admin)
            ->get('/admin/members/import')
            ->assertForbidden();
    }

    public function test_events_officer_blocked_from_orders(): void
    {
        $officer = $this->makeAdminUser(['role' => AdminRole::EventsOfficer]);

        $this->actingAsAdmin($officer)
            ->get('/admin/events/list')
            ->assertOk();

        $this->actingAsAdmin($officer)
            ->get('/admin/orders')
            ->assertForbidden();
    }

    public function test_super_admin_can_create_admin_user(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/users/create', [
            'name' => 'New Finance',
            'email' => 'new-finance@lfs.test',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'role' => AdminRole::Finance,
        ]);

        $response->assertRedirect('/admin/users');
        $this->assertTrue(
            AdminUser::query()->where('email', 'new-finance@lfs.test')->where('role', AdminRole::Finance)->exists()
        );
    }

    public function test_any_admin_can_view_profile(): void
    {
        $finance = $this->makeAdminUser(['role' => AdminRole::Finance, 'name' => 'Fin Profile']);

        $this->actingAsAdmin($finance)
            ->get('/admin/profile')
            ->assertOk()
            ->assertSee('Fin Profile', false);
    }

    private function createActiveMembership(User $user): void
    {
        Membership::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => (string) random_int(40000, 49999),
            'status' => 'active',
            'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'approved',
            'approved_by' => 'system:test',
            'joined_at' => now(),
            'start_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);
    }
}
