<?php

namespace Tests\Feature\Auth;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\MembershipService;
use Carbon\Carbon;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    public function test_create_account_uses_hero_layout_and_hides_nav(): void
    {
        $response = $this->get('/create-account');

        $response->assertOk();
        $response->assertSee('page-no-nav', false);
        $response->assertSee('page-header', false);
        $response->assertSee('page-breadcrumb', false);
        $response->assertDontSee('class="lfs-nav"', false);
        $response->assertDontSee('auth-split', false);
        $response->assertSee('lfs-form', false);
        $response->assertSee('auth.css', false);
    }

    public function test_other_signup_flow_pages_hide_nav_and_use_split_layout(): void
    {
        $verifiedUser = User::factory()->create();
        $unverifiedUser = User::factory()->unverified()->create();

        $pages = [
            '/login',
            ['/email/verify', $unverifiedUser],
            ['/membership/apply', $verifiedUser],
        ];

        foreach ($pages as $page) {
            if (is_array($page)) {
                [$url, $user] = $page;
                $response = $this->actingAs($user)->get($url);
            } else {
                $response = $this->get($page);
            }

            $response->assertOk();
            $response->assertSee('page-no-nav', false);
            $response->assertSee('auth-split', false);
            $response->assertSee('page-breadcrumb', false);
            $response->assertDontSee('class="lfs-nav"', false);
            $response->assertDontSee('class="page-header"', false);
            $response->assertSee('auth.css', false);
        }
    }

    public function test_forgot_password_renders_with_nav(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertOk();
        $response->assertSee('lfs-nav', false);
        $response->assertSee('lfs-form', false);
        $response->assertSee('auth.css', false);
    }

    public function test_account_page_renders_for_verified_user_with_membership(): void
    {
        $user = User::factory()->create();
        $plan = MembershipPlan::query()->first();

        app(MembershipService::class)->createApplication((int) $user->id, (int) $plan->id);

        $response = $this->actingAs($user)->get('/account');

        $response->assertOk();
        $response->assertSee('My Dashboard', false);
        $response->assertSee('account-tabs', false);
        $response->assertSee('Membership', false);
        $response->assertSee('Edit Profile', false);
        $response->assertSee($user->email, false);
        $response->assertSee('page-no-nav', false);
        $response->assertSee('page-breadcrumb', false);
        $response->assertDontSee('class="lfs-nav"', false);
    }

    public function test_payment_panel_offers_instalment_choices_only_inside_the_grace_window(): void
    {
        $user = User::factory()->create();
        app(MembershipService::class)->createApplication((int) $user->id, (int) MembershipPlan::query()->first()->id);

        Carbon::setTestNow('2026-02-10 10:00:00');
        $this->actingAs($user)->get('/account')->assertOk()
            ->assertSee('1. Choose your initial payment', false)
            ->assertSee('K250 initial payment', false);

        // 15 May: no instalments, full K1,000.
        Carbon::setTestNow('2026-05-15 10:00:00');
        $this->actingAs($user)->get('/account')->assertOk()
            ->assertDontSee('Choose your initial payment', false)
            ->assertDontSee('K250 initial payment', false)
            ->assertSee('(K1,000 now)', false);

        // 15 July: reduced late-joiner fee.
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->actingAs($user)->get('/account')->assertOk()
            ->assertDontSee('Choose your initial payment', false)
            ->assertSee('(K500 now)', false);

        Carbon::setTestNow();
    }

    public function test_renewal_form_shows_only_the_full_amount_after_the_grace_window(): void
    {
        $user = User::factory()->create();
        Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-RENEW-001',
            'status' => MembershipStatus::Expired,
            'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'approved',
            'start_date' => '2025-01-15',
            'expiry_date' => '2025-12-31',
        ]);

        Carbon::setTestNow('2026-02-10 10:00:00');
        $this->actingAs($user)->get('/account')->assertOk()
            ->assertSee('K250 initial payment', false)
            ->assertSee('Choose how much to pay now to renew', false);

        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->actingAs($user)->get('/account')->assertOk()
            ->assertDontSee('K250 initial payment', false)
            ->assertDontSee('K500 initial payment', false)
            ->assertSee('Renew by paying the amount below', false)
            ->assertSee('K500', false);

        Carbon::setTestNow();
    }
}
