<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionProxyStatus;
use App\Enums\ElectionStatus;
use App\Enums\ElectionVoterMatchStatus;
use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionAttendance;
use App\Models\ElectionProxy;
use App\Models\ElectionVoter;
use App\Models\User;
use App\Support\Uuid;
use Illuminate\Validation\ValidationException;

class ElectionQuorumService
{
    public function __construct(
        private readonly ElectionRollService $roll,
        private readonly ElectionAuditService $audit,
    ) {}

    /**
     * @return array{
     *   good_standing: int,
     *   attending: int,
     *   represented_by_proxy: int,
     *   quorum_percent: int,
     *   quorum_required: int,
     *   quorum_met: bool,
     *   quorum_confirmed_at: ?string
     * }
     */
    public function snapshot(Election $election): array
    {
        $goodStanding = $this->roll->goodStandingCount($election);
        $attending = ElectionAttendance::query()->where('election_id', $election->id)->count();
        $proxies = ElectionProxy::query()
            ->where('election_id', $election->id)
            ->where('status', ElectionProxyStatus::Approved)
            ->count();

        $percent = (int) $election->quorum_percent;
        $required = (int) ceil($goodStanding * ($percent / 100));

        // A member counts toward quorum if they're checked in themselves, or
        // if they're represented by an approved proxy (their proxy holder
        // effectively stands in for them at the meeting).
        $counted = ElectionVoter::query()
            ->where('election_id', $election->id)
            ->whereNull('excluded_at')
            ->where('match_status', ElectionVoterMatchStatus::Matched)
            ->where(function ($q) use ($election) {
                $q->whereIn('user_id', ElectionAttendance::query()
                    ->where('election_id', $election->id)
                    ->select('user_id'))
                    ->orWhere('represented_by_proxy', true);
            })
            ->count();

        return [
            'good_standing' => $goodStanding,
            'attending' => $attending,
            'represented_by_proxy' => $proxies,
            'quorum_percent' => $percent,
            'quorum_required' => $required,
            'quorum_met' => $counted >= $required && $required > 0,
            'quorum_confirmed_at' => $election->quorum_confirmed_at?->toIso8601String(),
            'counted_for_quorum' => $counted,
        ];
    }

    public function checkIn(Election $election, User $user): ElectionAttendance
    {
        // Check-in is allowed once the election is scheduled through polls
        // being open; being present must never itself cast a vote.
        if (! in_array($election->status, [
            ElectionStatus::Scheduled,
            ElectionStatus::BallotApproved,
            ElectionStatus::Open,
        ], true)) {
            throw ValidationException::withMessages(['attendance' => 'Check-in is not available for this election.']);
        }

        $onRoll = ElectionVoter::query()
            ->where('election_id', $election->id)
            ->where('user_id', $user->id)
            ->whereNull('excluded_at')
            ->where('match_status', ElectionVoterMatchStatus::Matched)
            ->exists();

        if (! $onRoll) {
            throw ValidationException::withMessages(['attendance' => 'You are not on the voters’ roll for this meeting.']);
        }

        $existing = ElectionAttendance::query()
            ->where('election_id', $election->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $attendance = ElectionAttendance::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'user_id' => $user->id,
            'checked_in_at' => now(),
        ]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::AttendanceCheckedIn,
            'member',
            (string) $user->id,
        );

        return $attendance;
    }

    public function confirmQuorum(Election $election, AdminUser $admin): Election
    {
        $snap = $this->snapshot($election);
        if (! $snap['quorum_met']) {
            throw ValidationException::withMessages(['quorum' => 'Quorum has not been achieved.']);
        }

        $election->update(['quorum_confirmed_at' => now()]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::QuorumConfirmed,
            'admin',
            (string) $admin->id,
            $snap,
        );

        return $election->fresh();
    }
}
