<?php

namespace Tests\Feature\Auth;

use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\MembershipService;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
