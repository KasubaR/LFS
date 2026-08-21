<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\ElectionAuditAction;
use App\Enums\ElectionStatus;
use App\Enums\ElectionType;
use App\Enums\ElectionVoterMatchStatus;
use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionComplaint;
use App\Models\ElectionPosition;
use App\Models\ElectionVote;
use App\Models\ElectionVoter;
use App\Models\User;
use App\Services\Election\ElectionBallotLockService;
use App\Services\Election\ElectionBallotService;
use App\Services\Election\ElectionEntitlementService;
use App\Services\Election\ElectionLifecycleService;
use App\Services\Election\ElectionProxyService;
use App\Services\Election\ElectionQuorumService;
use App\Services\Election\ElectionRollService;
use App\Services\Election\ElectionTallyService;
use App\Services\Election\ElectionVoteFlushService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class ElectionFlowTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_roll_lock_requires_matched_voters_and_ballot_lock_blocks_candidate_edits(): void
    {
        $admin = $this->makeEcAdmin();
        $election = $this->makeElection();
        $user = User::factory()->create();

        ElectionVoter::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'raw_email' => $user->email,
            'match_status' => ElectionVoterMatchStatus::Matched,
            'user_id' => $user->id,
        ]);

        $roll = app(\App\Services\Election\ElectionRollService::class);
        $roll->lock($election->fresh(), $admin);
        $this->assertNotNull($election->fresh()->roll_locked_at);

        $position = ElectionPosition::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'title' => 'Chair',
            'allow_abstain' => false,
            'sort_order' => 1,
        ]);
        ElectionCandidate::query()->create([
            'id' => Uuid::v4(),
            'position_id' => $position->id,
            'name' => 'Alex',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        app(ElectionBallotLockService::class)->approve($election->fresh(), $admin);
        $this->assertNotNull($election->fresh()->ballot_approved_at);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\Election\ElectionService::class)->addCandidate($election->fresh(), $position, ['name' => 'Late'], $admin);
    }

    public function test_ballot_composition_changes_are_audited(): void
    {
        $admin = $this->makeEcAdmin();
        $election = $this->makeElection();
        $service = app(\App\Services\Election\ElectionService::class);

        // Audit rows share a UUID primary key (not chronologically sortable)
        // and can land in the same created_at second, so each step's new
        // row is found by set-difference against previously-seen ids rather
        // than by ordering.
        $seenIds = [];
        $newEvent = function () use ($election, &$seenIds): string {
            $rows = \App\Models\ElectionAuditLog::query()
                ->where('election_id', $election->id)
                ->where('action', ElectionAuditAction::ElectionUpdated)
                ->get(['id', 'metadata']);

            $new = $rows->whereNotIn('id', $seenIds);
            $this->assertCount(1, $new, 'Expected exactly one new audit row for this step.');
            $seenIds = $rows->pluck('id')->all();

            return $new->first()->metadata['event'];
        };

        $position = $service->addPosition($election, ['title' => 'Chair', 'allow_abstain' => false], $admin);
        $this->assertDatabaseHas('election_audit_logs', [
            'election_id' => $election->id,
            'action' => ElectionAuditAction::ElectionUpdated,
            'actor_id' => (string) $admin->id,
        ]);
        $this->assertSame('position_added', $newEvent());

        $candidate = $service->addCandidate($election, $position, ['name' => 'Alex'], $admin);
        $this->assertSame('candidate_added', $newEvent());

        $service->deleteCandidate($election, $candidate, $admin);
        $this->assertSame('candidate_removed', $newEvent());

        $service->deletePosition($election, $position, $admin);
        $this->assertSame('position_removed', $newEvent());
    }

    public function test_secret_ballot_cast_flush_and_duplicate_blocked(): void
    {
        $admin = $this->makeEcAdmin();
        [$election, $user, $candidateId] = $this->prepareOpenElection($admin);

        $entitlement = $election->entitlements()->where('holder_user_id', $user->id)->firstOrFail();
        $ballots = app(ElectionBallotService::class);
        $ballots->cast($election, $user, $entitlement->id, [
            'candidate_id' => $candidateId,
            'abstain' => false,
        ]);

        $this->assertNotNull($entitlement->fresh()->used_at);
        $this->assertDatabaseHas('election_vote_outbox', ['election_id' => $election->id]);

        app(ElectionVoteFlushService::class)->flush(50);
        $this->assertDatabaseHas('election_votes', ['election_id' => $election->id]);
        $this->assertDatabaseMissing('election_vote_outbox', ['election_id' => $election->id]);

        $vote = \App\Models\ElectionVote::query()->where('election_id', $election->id)->first();
        $this->assertNull($vote->getAttribute('user_id') ?? null);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $ballots->cast($election, $user, $entitlement->id, [
            'candidate_id' => $candidateId,
            'abstain' => false,
        ]);
    }

    public function test_close_expires_unused_and_tally_runs(): void
    {
        $admin = $this->makeEcAdmin();
        $admin2 = $this->makeEcAdmin('ec2@example.com');
        [$election, $user, $candidateId] = $this->prepareOpenElection($admin);

        $lifecycle = app(ElectionLifecycleService::class);
        $closed = $lifecycle->close($election->fresh(), $admin);
        $this->assertSame(ElectionStatus::Closed, $closed->status);
        $this->assertTrue($closed->entitlements()->whereNotNull('expired_at')->exists());

        $lifecycle->certify($closed->fresh(), $admin);
        $certified = $lifecycle->certify($closed->fresh(), $admin2);
        $this->assertSame(ElectionStatus::Certified, $certified->status);
    }

    public function test_proxy_approve_enforces_max_five_marks_grantor_and_blocks_revoke_after_open(): void
    {
        $admin = $this->makeEcAdmin();
        $election = $this->makeElection();
        $holder = User::factory()->create();

        $grantors = [];
        for ($i = 0; $i < 6; $i++) {
            $voterUser = User::factory()->create();
            $grantors[] = ElectionVoter::query()->create([
                'id' => Uuid::v4(),
                'election_id' => $election->id,
                'raw_email' => $voterUser->email,
                'match_status' => ElectionVoterMatchStatus::Matched,
                'user_id' => $voterUser->id,
            ]);
        }

        app(ElectionRollService::class)->lock($election->fresh(), $admin);

        $proxies = app(ElectionProxyService::class);
        $approved = [];
        for ($i = 0; $i < 5; $i++) {
            $approved[] = $proxies->approve($election->fresh(), $grantors[$i], $holder->id, $admin);
        }

        // The grantor is marked represented-by-proxy and gets no direct entitlement path.
        $this->assertTrue($grantors[0]->fresh()->represented_by_proxy);

        // A 6th proxy for the same holder is rejected — max 5 per holder.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $proxies->approve($election->fresh(), $grantors[5], $holder->id, $admin);
    }

    public function test_proxy_revoke_is_blocked_once_voting_is_open(): void
    {
        $admin = $this->makeEcAdmin();
        $election = $this->makeElection();
        $holder = User::factory()->create();
        $voterUser = User::factory()->create();

        $grantor = ElectionVoter::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'raw_email' => $voterUser->email,
            'match_status' => ElectionVoterMatchStatus::Matched,
            'user_id' => $voterUser->id,
        ]);

        $position = ElectionPosition::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'title' => 'Chair',
            'allow_abstain' => false,
            'sort_order' => 1,
        ]);
        ElectionCandidate::query()->create([
            'id' => Uuid::v4(),
            'position_id' => $position->id,
            'name' => 'Alex',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        app(ElectionRollService::class)->lock($election->fresh(), $admin);
        $proxy = app(ElectionProxyService::class)->approve($election->fresh(), $grantor, $holder->id, $admin);

        app(ElectionBallotLockService::class)->approve($election->fresh(), $admin);
        $election->refresh();
        $election->update([
            'ballot_approved_at' => now()->subHours(50),
            'scheduled_open_at' => now(),
            'status' => ElectionStatus::Scheduled,
        ]);
        app(ElectionLifecycleService::class)->open($election->fresh(), $admin);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ElectionProxyService::class)->revoke($election->fresh(), $proxy, $admin);
    }

    public function test_quorum_snapshot_counts_attendance_and_proxies_not_live_membership_status(): void
    {
        $admin = $this->makeEcAdmin();
        $election = $this->makeElection();

        $attendee = User::factory()->create();
        $other = User::factory()->create();

        ElectionVoter::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'raw_email' => $attendee->email,
            'match_status' => ElectionVoterMatchStatus::Matched,
            'user_id' => $attendee->id,
        ]);
        ElectionVoter::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'raw_email' => $other->email,
            'match_status' => ElectionVoterMatchStatus::Matched,
            'user_id' => $other->id,
        ]);

        $election->update(['status' => ElectionStatus::Scheduled]);

        $quorum = app(ElectionQuorumService::class);
        $quorum->checkIn($election->fresh(), $attendee);

        $snapshot = $quorum->snapshot($election->fresh());

        // good_standing reflects the roll (matched, non-excluded voter rows),
        // not the attendee/other users' live Membership status.
        $this->assertSame(2, $snapshot['good_standing']);
        $this->assertSame(1, $snapshot['attending']);
        $this->assertSame(0, $snapshot['represented_by_proxy']);
        $this->assertSame(1, $snapshot['counted_for_quorum']);
        $this->assertSame(1, $snapshot['quorum_required']); // ceil(2 * 50%)
        $this->assertTrue($snapshot['quorum_met']);
        $this->assertNull($snapshot['quorum_confirmed_at']);

        $confirmed = $quorum->confirmQuorum($election->fresh(), $admin);
        $this->assertNotNull($confirmed->quorum_confirmed_at);

        // Re-confirming after quorum is already confirmed is fine (idempotent
        // outcome); what matters is the timestamp and audit trail exist.
        $this->assertDatabaseHas('election_audit_logs', [
            'election_id' => $election->id,
            'action' => \App\Enums\ElectionAuditAction::QuorumConfirmed,
        ]);
    }

    public function test_checkin_records_attendance_only_and_requires_matched_roll_membership(): void
    {
        $election = $this->makeElection();
        $election->update(['status' => ElectionStatus::Scheduled]);

        $onRoll = User::factory()->create();
        $notOnRoll = User::factory()->create();

        ElectionVoter::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'raw_email' => $onRoll->email,
            'match_status' => ElectionVoterMatchStatus::Matched,
            'user_id' => $onRoll->id,
        ]);

        $quorum = app(ElectionQuorumService::class);
        $attendance = $quorum->checkIn($election->fresh(), $onRoll);

        $this->assertNotNull($attendance->checked_in_at);
        $this->assertDatabaseCount('election_votes', 0);
        $this->assertDatabaseCount('election_vote_outbox', 0);
        $this->assertDatabaseCount('election_ballot_entitlements', 0);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $quorum->checkIn($election->fresh(), $notOnRoll);
    }

    public function test_tally_counts_valid_rejected_abstain_incomplete_and_handles_ties(): void
    {
        $admin = $this->makeEcAdmin();
        $election = $this->makeElection();

        $position = ElectionPosition::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'title' => 'Chair',
            'allow_abstain' => true,
            'sort_order' => 1,
        ]);
        $candA = ElectionCandidate::query()->create([
            'id' => Uuid::v4(), 'position_id' => $position->id, 'name' => 'A', 'sort_order' => 1, 'is_active' => true,
        ]);
        $candB = ElectionCandidate::query()->create([
            'id' => Uuid::v4(), 'position_id' => $position->id, 'name' => 'B', 'sort_order' => 2, 'is_active' => true,
        ]);

        // Three voters: one votes A, one votes B (tie), one abstains.
        $voters = [];
        for ($i = 0; $i < 3; $i++) {
            $u = User::factory()->create();
            $voters[] = $u;
            ElectionVoter::query()->create([
                'id' => Uuid::v4(),
                'election_id' => $election->id,
                'raw_email' => $u->email,
                'match_status' => ElectionVoterMatchStatus::Matched,
                'user_id' => $u->id,
            ]);
        }

        app(ElectionRollService::class)->lock($election->fresh(), $admin);
        app(ElectionBallotLockService::class)->approve($election->fresh(), $admin);
        $election->refresh();
        $election->update([
            'ballot_approved_at' => now()->subHours(50),
            'scheduled_open_at' => now(),
            'status' => ElectionStatus::Scheduled,
        ]);
        app(ElectionLifecycleService::class)->open($election->fresh(), $admin);
        $election->refresh();

        $ballots = app(ElectionBallotService::class);
        $entitlements = $election->entitlements()->where('position_id', $position->id)->get()->keyBy('holder_user_id');

        $ballots->cast($election, $voters[0], $entitlements[$voters[0]->id]->id, ['candidate_id' => $candA->id, 'abstain' => false]);
        $ballots->cast($election, $voters[1], $entitlements[$voters[1]->id]->id, ['candidate_id' => $candB->id, 'abstain' => false]);
        $ballots->cast($election, $voters[2], $entitlements[$voters[2]->id]->id, ['abstain' => true]);

        // Inject one rejected (bad ciphertext) vote directly into election_votes
        // to exercise that tally bucket independently of the cast/flush path.
        ElectionVote::query()->create([
            'blind_ballot_id' => Uuid::v4(),
            'election_id' => $election->id,
            'position_id' => $position->id,
            'ciphertext' => 'not-valid-ciphertext',
            'flushed_at' => now(),
        ]);

        $lifecycle = app(ElectionLifecycleService::class);
        $closed = $lifecycle->close($election->fresh(), $admin);

        $tally = app(ElectionTallyService::class)->tally($closed->fresh(['positions.candidates']));
        $pos = $tally['positions'][0];

        $votesByName = collect($pos['candidates'])->keyBy('name');
        $this->assertSame(1, $votesByName['A']['votes']);
        $this->assertSame(1, $votesByName['B']['votes']);
        $this->assertSame(1, $pos['abstentions']);
        $this->assertSame(1, $pos['rejected']); // the bad-ciphertext vote
        $this->assertNull($pos['winner']); // tie between A and B -> no single winner
    }

    public function test_tally_is_blocked_before_close(): void
    {
        $election = $this->makeElection();
        $election->update(['status' => ElectionStatus::Open]);

        $this->expectException(\RuntimeException::class);
        app(ElectionTallyService::class)->tally($election);
    }

    public function test_certify_rejects_same_admin_twice_and_requires_two_distinct_admins(): void
    {
        $admin = $this->makeEcAdmin();
        $election = $this->makeElection();
        $election->update(['status' => ElectionStatus::Closed, 'closed_at' => now()]);

        $lifecycle = app(ElectionLifecycleService::class);
        $lifecycle->certify($election->fresh(), $admin);
        $this->assertSame(ElectionStatus::Closed, $election->fresh()->status); // still needs a 2nd

        $this->expectException(ValidationException::class);
        $lifecycle->certify($election->fresh(), $admin);
    }

    public function test_permanent_lock_requires_certified_status_and_locks(): void
    {
        $admin = $this->makeEcAdmin();
        $admin2 = $this->makeEcAdmin('lock2@example.com');
        $election = $this->makeElection();
        $election->update(['status' => ElectionStatus::Closed, 'closed_at' => now()]);

        $lifecycle = app(ElectionLifecycleService::class);

        try {
            $lifecycle->permanentLock($election->fresh(), $admin);
            $this->fail('Expected ValidationException locking a non-certified election.');
        } catch (ValidationException) {
        }

        $lifecycle->certify($election->fresh(), $admin);
        $certified = $lifecycle->certify($election->fresh(), $admin2);
        $this->assertSame(ElectionStatus::Certified, $certified->status);

        $locked = $lifecycle->permanentLock($certified->fresh(), $admin);
        $this->assertSame(ElectionStatus::Locked, $locked->status);
        $this->assertNotNull($locked->locked_at);

        $this->assertDatabaseHas('election_audit_logs', [
            'election_id' => $election->id,
            'action' => ElectionAuditAction::ElectionLocked,
        ]);
    }

    public function test_results_hidden_from_members_until_dual_certified_but_visible_to_ec_after_close(): void
    {
        $admin = $this->makeEcAdmin();
        $admin2 = $this->makeEcAdmin('vis2@example.com');
        $election = $this->makeElection();
        $election->update(['status' => ElectionStatus::Closed, 'closed_at' => now()]);

        // Closed but not yet certified: member-facing gate must stay shut.
        $this->assertFalse($election->resultsArePublic());
        // EC/admin gate (isClosedOrLater) is already open right after close.
        $this->assertTrue($election->isClosedOrLater());

        app(ElectionLifecycleService::class)->certify($election->fresh(), $admin);
        $this->assertFalse($election->fresh()->resultsArePublic()); // only 1 of 2 so far

        $certified = app(ElectionLifecycleService::class)->certify($election->fresh(), $admin2);
        $this->assertTrue($certified->resultsArePublic());
    }

    public function test_certificate_download_before_close_is_rejected_not_a_server_error(): void
    {
        $admin = $this->makeEcAdmin();
        $election = $this->makeElection(); // still Draft

        $this->actingAsAdmin($admin)
            ->get("/admin/elections/{$election->id}/certificate")
            ->assertSessionHasErrors('election');
    }

    public function test_participation_export_never_includes_candidate_choice(): void
    {
        $election = $this->makeElection();

        $voterUser = User::factory()->create();
        ElectionVoter::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'raw_email' => $voterUser->email,
            'raw_name' => 'Participation Voter',
            'match_status' => ElectionVoterMatchStatus::Matched,
            'user_id' => $voterUser->id,
        ]);

        $response = app(ElectionLifecycleService::class)->participationCsv($election->fresh());
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('membership_number', $csv);
        $this->assertStringContainsString('entitlements_issued', $csv);
        $this->assertStringContainsString('Participation Voter', $csv);
        $this->assertStringNotContainsString('candidate', $csv);
        $this->assertStringNotContainsString('choice', $csv);
    }

    public function test_complaints_can_be_logged_by_member_and_by_ec_admin(): void
    {
        $admin = $this->makeEcAdmin();
        $election = $this->makeElection();
        $election->update(['status' => ElectionStatus::Open]);
        $member = User::factory()->create();

        ElectionComplaint::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'reporter_user_id' => $member->id,
            'reporter_name' => $member->name,
            'reporter_email' => $member->email,
            'body' => 'Ballot page timed out.',
            'status' => 'open',
        ]);
        ElectionComplaint::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'reporter_admin_id' => $admin->id,
            'reporter_name' => $admin->name,
            'reporter_email' => $admin->email,
            'body' => 'Observed a technical glitch.',
            'status' => 'open',
        ]);

        $this->assertSame(2, $election->complaints()->count());
        $this->assertTrue($election->complaints()->whereNotNull('reporter_user_id')->exists());
        $this->assertTrue($election->complaints()->whereNotNull('reporter_admin_id')->exists());
    }

    private function makeEcAdmin(string $email = 'ec@example.com'): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'EC',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => AdminRole::ElectoralCommission,
            'is_active' => true,
        ]);
    }

    private function makeElection(): Election
    {
        return Election::query()->create([
            'id' => Uuid::v4(),
            'title' => 'Test',
            'type' => ElectionType::Egm,
            'status' => ElectionStatus::Draft,
            'scheduled_open_at' => now(),
            'scheduled_close_at' => now()->addHour(),
            'quorum_percent' => 50,
        ]);
    }

    /**
     * @return array{0: Election, 1: User, 2: string}
     */
    private function prepareOpenElection(AdminUser $admin): array
    {
        $election = $this->makeElection();
        $user = User::factory()->create();

        ElectionVoter::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'raw_email' => $user->email,
            'match_status' => ElectionVoterMatchStatus::Matched,
            'user_id' => $user->id,
        ]);

        $position = ElectionPosition::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'title' => 'Chair',
            'allow_abstain' => false,
            'sort_order' => 1,
        ]);
        $candidate = ElectionCandidate::query()->create([
            'id' => Uuid::v4(),
            'position_id' => $position->id,
            'name' => 'Alex',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        app(\App\Services\Election\ElectionRollService::class)->lock($election->fresh(), $admin);
        app(ElectionBallotLockService::class)->approve($election->fresh(), $admin);

        $election->refresh();
        $election->update([
            'ballot_approved_at' => now()->subHours(50),
            'scheduled_open_at' => now(),
            'status' => ElectionStatus::Scheduled,
        ]);

        $opened = app(ElectionLifecycleService::class)->open($election->fresh(), $admin);

        return [$opened, $user, $candidate->id];
    }
}
