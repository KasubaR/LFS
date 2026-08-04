<?php

namespace Tests\Feature\Auth;

use App\Enums\MembershipStatus;
use App\Enums\TShirtSize;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Satellite;
use App\Models\User;
use App\Support\Uuid;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    private function member(array $membershipOverrides = []): array
    {
        $user = User::factory()->create([
            'phone' => '+260971111111',
            'gender' => 'male',
            'nationality' => 'Zambian',
            'town' => 'Lusaka',
            't_shirt_size' => TShirtSize::M,
            'satellite_id' => Satellite::query()->first()->id,
        ]);

        $plan = MembershipPlan::query()->first();
        $token = Uuid::v4();

        $membership = Membership::query()->create(array_merge([
            'id' => Uuid::v4(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-CARD-001',
            'public_token' => $token,
            'status' => MembershipStatus::Active,
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
            'start_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addMonths(5)->toDateString(),
        ], $membershipOverrides));

        return [$user, $membership];
    }

    public function test_member_with_active_membership_can_open_card_and_see_qr(): void
    {
        [$user, $membership] = $this->member();

        $response = $this->actingAs($user)->get('/account/card');

        $response->assertOk();
        $response->assertSee('Member Card', false);
        $response->assertSee(e($user->name), false);
        $response->assertSee('LFS-CARD-001', false);
        $response->assertSee('Active', false);
        $response->assertSee($user->satellite->name, false);
        $response->assertSee('<svg', false);
        $response->assertSee('Save as PDF', false);
        $response->assertSee('/account/card/pdf', false);
    }

    public function test_member_can_download_membership_card_pdf(): void
    {
        [$user] = $this->member();

        $response = $this->actingAs($user)->get('/account/card/pdf');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_pending_membership_without_number_sees_empty_state(): void
    {
        [$user] = $this->member([
            'membership_number' => null,
            'public_token' => null,
            'status' => MembershipStatus::PendingPayment,
            'start_date' => null,
            'expiry_date' => null,
        ]);

        $response = $this->actingAs($user)->get('/account/card');

        $response->assertOk();
        $response->assertSee('Card not ready yet', false);
        $response->assertDontSee('<svg', false);
        $response->assertDontSee('Save as PDF', false);
    }

    public function test_public_verify_page_renders_active_details_for_valid_token(): void
    {
        [$user, $membership] = $this->member();

        $response = $this->get('/membership/verify/'.$membership->public_token);

        $firstName = explode(' ', trim($user->name), 2)[0];

        $response->assertOk();
        $response->assertSee($firstName, false);
        $response->assertSee('LFS-CARD-001', false);
        $response->assertSee('Active', false);
        $response->assertSee($user->satellite->name, false);
        $response->assertSee($membership->expiry_date->format('j M Y'), false);
        $response->assertDontSee($user->email, false);
        $response->assertDontSee($user->phone, false);
    }

    public function test_expired_membership_does_not_show_downloadable_card(): void
    {
        [$user] = $this->member([
            'status' => MembershipStatus::Expired,
            'expiry_date' => now()->subDays(10)->toDateString(),
        ]);

        $this->actingAs($user)->get('/account/card')
            ->assertOk()
            ->assertSee('Card not ready yet', false)
            ->assertDontSee('Save as PDF', false);

        $this->actingAs($user)->get('/account/card/pdf')->assertNotFound();
    }

    public function test_expired_membership_shows_expired_badge_on_verify_page(): void
    {
        [, $membership] = $this->member([
            'status' => MembershipStatus::Expired,
            'expiry_date' => now()->subDays(10)->toDateString(),
        ]);

        $response = $this->get('/membership/verify/'.$membership->public_token);

        $response->assertOk();
        $response->assertSee('Expired', false);
        $response->assertDontSee('membership-verify__badge--active', false);
        $response->assertSee('membership-verify__badge--expired', false);
    }

    public function test_unknown_token_returns_404(): void
    {
        $response = $this->get('/membership/verify/'.Uuid::v4());

        $response->assertNotFound();
    }

    public function test_guest_cannot_open_account_card(): void
    {
        $response = $this->get('/account/card');

        $response->assertRedirect('/login');
    }

    public function test_dashboard_opens_membership_card_in_lightbox(): void
    {
        [$user] = $this->member();

        $response = $this->actingAs($user)->get('/account');

        $response->assertOk();
        $response->assertSee('View membership card', false);
        $response->assertSee('data-open-membership-card', false);
        $response->assertSee('membership-card-lightbox', false);
        $response->assertSee('Member Card', false);
        $response->assertSee('LFS-CARD-001', false);
        $response->assertSee('<svg', false);
    }
}
