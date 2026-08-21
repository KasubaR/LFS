<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\ElectionStatus;
use App\Enums\ElectionType;
use App\Enums\ElectionVoterMatchStatus;
use App\Enums\TShirtSize;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionPosition;
use App\Models\ElectionVoter;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Satellite;
use App\Models\User;
use App\Services\Election\ElectionBallotLockService;
use App\Services\Election\ElectionLifecycleService;
use App\Support\Uuid;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

/**
 * HTTP-level coverage for the admin election CRUD endpoints (Phase 1) and the
 * member-facing cast endpoint (Phase 2). ElectionFlowTest exercises the same
 * behaviour at the service layer; this file drives it through the actual
 * routes/controllers/middleware stack instead.
 */
class ElectionHttpFlowTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    public function test_ec_admin_can_create_election_and_add_positions_and_candidates_over_http(): void
    {
        $ec = $this->makeAdminUser(['role' => AdminRole::ElectoralCommission]);

        $create = $this->actingAsAdmin($ec)->post('/admin/elections/create', [
            'title' => 'Annual General Meeting 2026',
            'type' => ElectionType::Agm,
            'description' => 'Test election created over HTTP.',
            'scheduled_open_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
            'scheduled_close_at' => now()->addDays(6)->format('Y-m-d\TH:i'),
            'quorum_percent' => 50,
        ]);

        $election = Election::query()->where('title', 'Annual General Meeting 2026')->firstOrFail();
        $create->assertRedirect('/admin/elections/'.$election->id);

        $update = $this->actingAsAdmin($ec)->post('/admin/elections/'.$election->id, [
            'title' => 'Annual General Meeting 2026 (Updated)',
            'type' => ElectionType::Agm,
            'description' => 'Updated description.',
            'scheduled_open_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
            'scheduled_close_at' => now()->addDays(6)->format('Y-m-d\TH:i'),
            'quorum_percent' => 60,
        ]);
        $update->assertRedirect();
        $this->assertSame('Annual General Meeting 2026 (Updated)', $election->fresh()->title);
        $this->assertSame(60, $election->fresh()->quorum_percent);

        $position = $this->actingAsAdmin($ec)->post("/admin/elections/{$election->id}/positions", [
            'title' => 'Chairperson',
            'allow_abstain' => '1',
        ]);
        $position->assertRedirect();
        $this->assertDatabaseHas('election_positions', [
            'election_id' => $election->id,
            'title' => 'Chairperson',
        ]);

        $positionModel = ElectionPosition::query()->where('election_id', $election->id)->firstOrFail();

        $candidate = $this->actingAsAdmin($ec)->post(
            "/admin/elections/{$election->id}/positions/{$positionModel->id}/candidates",
            ['name' => 'Jane Runner']
        );
        $candidate->assertRedirect();
        $this->assertDatabaseHas('election_candidates', [
            'position_id' => $positionModel->id,
            'name' => 'Jane Runner',
        ]);

        // An observer (read-only) must not be able to reach the same write routes.
        $observer = $this->makeAdminUser(['role' => AdminRole::ElectionObserver]);
        $this->actingAsAdmin($observer)
            ->post('/admin/elections/create', [
                'title' => 'Observer Should Not Create This',
                'type' => ElectionType::Egm,
            ])
            ->assertForbidden();
    }

    public function test_member_can_cast_ballot_over_http_and_duplicate_cast_is_rejected(): void
    {
        $ec = $this->makeAdminUser(['role' => AdminRole::ElectoralCommission]);
        $member = $this->makeEligibleMember();

        $election = Election::query()->create([
            'id' => Uuid::v4(),
            'title' => 'By-election 2026',
            'type' => ElectionType::ByElection,
            'status' => ElectionStatus::Draft,
            'scheduled_open_at' => now(),
            'scheduled_close_at' => now()->addHour(),
            'quorum_percent' => 50,
        ]);

        ElectionVoter::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'raw_email' => $member->email,
            'match_status' => ElectionVoterMatchStatus::Matched,
            'user_id' => $member->id,
        ]);

        $position = ElectionPosition::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'title' => 'Treasurer',
            'allow_abstain' => false,
            'sort_order' => 1,
        ]);
        $candidate = ElectionCandidate::query()->create([
            'id' => Uuid::v4(),
            'position_id' => $position->id,
            'name' => 'Sam Voter',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        app(\App\Services\Election\ElectionRollService::class)->lock($election->fresh(), $ec);
        app(ElectionBallotLockService::class)->approve($election->fresh(), $ec);

        $election->refresh();
        $election->update([
            'ballot_approved_at' => now()->subHours(50),
            'scheduled_open_at' => now(),
            'status' => ElectionStatus::Scheduled,
        ]);
        app(ElectionLifecycleService::class)->open($election->fresh(), $ec);
        $election->refresh();

        $entitlement = $election->entitlements()->where('holder_user_id', $member->id)->firstOrFail();

        // Ballot page renders the position/candidate/abstain/confirm form.
        $ballotPage = $this->actingAs($member)->get('/account/elections/'.$election->id);
        $ballotPage->assertOk();
        $ballotPage->assertSee('Treasurer', false);
        $ballotPage->assertSee('Sam Voter', false);
        $ballotPage->assertSee('cannot be changed after submission', false);

        $castUrl = "/account/elections/{$election->id}/entitlements/{$entitlement->id}/cast";

        $cast = $this->actingAs($member)->post($castUrl, [
            'choice' => 'candidate:'.$candidate->id,
            'confirm' => '1',
        ]);
        $cast->assertRedirect(route('account.elections.show', $election->id));

        $this->assertNotNull($entitlement->fresh()->used_at);
        $this->assertDatabaseHas('election_vote_outbox', ['election_id' => $election->id]);

        // Second submission on the same, now-used entitlement must be rejected.
        $duplicate = $this->actingAs($member)->post($castUrl, [
            'choice' => 'candidate:'.$candidate->id,
            'confirm' => '1',
        ]);
        $duplicate->assertSessionHasErrors('vote');
        $this->assertSame(1, \App\Models\ElectionVoteOutbox::query()->where('election_id', $election->id)->count());

        // The used ballot no longer appears on the member's page.
        $afterCast = $this->actingAs($member)->get('/account/elections/'.$election->id);
        $afterCast->assertOk();
        $afterCast->assertSee('You have no remaining ballot entitlements', false);
    }

    private function makeEligibleMember(): User
    {
        $user = User::factory()->create([
            'phone' => '+260971111199',
            'gender' => 'male',
            'nationality' => 'Zambian',
            'town' => 'Lusaka',
            't_shirt_size' => TShirtSize::M,
            'satellite_id' => Satellite::query()->first()->id,
        ]);

        Membership::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-ELECT-001',
            'status' => 'active',
            'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'approved',
            'start_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addMonths(11)->toDateString(),
        ]);

        return $user;
    }
}
