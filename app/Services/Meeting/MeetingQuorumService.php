<?php

namespace App\Services\Meeting;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionProxyStatus;
use App\Enums\ElectionVoterMatchStatus;
use App\Enums\MeetingStatus;
use App\Models\AdminUser;
use App\Models\ElectionProxy;
use App\Models\ElectionVoter;
use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\User;
use App\Services\Election\ElectionAuditService;
use App\Services\Election\ElectionRollService;
use App\Support\Uuid;
use Illuminate\Validation\ValidationException;

/**
 * Quorum & attendance for a Meeting (developer brief §8). Attendance is
 * meeting-scoped, but "good standing" and "represented by proxy" are still
 * governed by the voters' roll of the meeting's linked election — the roll
 * remains the Electoral Commission's source of truth for who is eligible,
 * per the earlier decision to keep goodStandingCount() roll-based rather
 * than cross-checking live membership status.
 */
class MeetingQuorumService
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
     *   quorum_confirmed_at: ?string,
     *   counted_for_quorum: int
     * }
     */
    public function snapshot(Meeting $meeting): array
    {
        $election = $meeting->primaryElection();

        if (! $election) {
            // A meeting with no linked election has no roll to measure
            // good standing/proxy representation against.
            return [
                'good_standing' => 0,
                'attending' => $meeting->attendances()->count(),
                'represented_by_proxy' => 0,
                'quorum_percent' => (int) $meeting->quorum_percent,
                'quorum_required' => 0,
                'quorum_met' => false,
                'quorum_confirmed_at' => $meeting->quorum_confirmed_at?->toIso8601String(),
                'counted_for_quorum' => 0,
            ];
        }

        $goodStanding = $this->roll->goodStandingCount($election);
        $attending = $meeting->attendances()->count();
        $proxies = ElectionProxy::query()
            ->where('election_id', $election->id)
            ->where('status', ElectionProxyStatus::Approved)
            ->count();

        $percent = (int) $meeting->quorum_percent;
        $required = (int) ceil($goodStanding * ($percent / 100));

        // A member counts toward quorum if they're checked in to the
        // meeting themselves, or if they're represented by an approved
        // proxy (their proxy holder effectively stands in for them).
        $counted = ElectionVoter::query()
            ->where('election_id', $election->id)
            ->whereNull('excluded_at')
            ->where('match_status', ElectionVoterMatchStatus::Matched)
            ->where(function ($q) use ($meeting) {
                $q->whereIn('user_id', $meeting->attendances()->select('user_id'))
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
            'quorum_confirmed_at' => $meeting->quorum_confirmed_at?->toIso8601String(),
            'counted_for_quorum' => $counted,
        ];
    }

    /**
     * @param  'manual'|'virtual'  $method
     */
    public function checkIn(Meeting $meeting, User $user, string $method = 'manual', ?string $hmsPeerId = null): MeetingAttendance
    {
        // Check-in is allowed once the meeting is scheduled through it
        // being live; being present must never itself cast a vote.
        if (! in_array($meeting->status, [MeetingStatus::Scheduled, MeetingStatus::Live], true)) {
            throw ValidationException::withMessages(['attendance' => 'Check-in is not available for this meeting.']);
        }

        $election = $meeting->primaryElection();
        $onRoll = $election && ElectionVoter::query()
            ->where('election_id', $election->id)
            ->where('user_id', $user->id)
            ->whereNull('excluded_at')
            ->where('match_status', ElectionVoterMatchStatus::Matched)
            ->exists();

        if (! $onRoll) {
            throw ValidationException::withMessages(['attendance' => 'You are not on the voters’ roll for this meeting.']);
        }

        $existing = MeetingAttendance::query()
            ->where('meeting_id', $meeting->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            // A rejoin (e.g. a dropped call reconnecting) should not lose
            // the original check-in time, but does upgrade a manual
            // check-in to a confirmed virtual one.
            if ($method === 'virtual' && $existing->method !== 'virtual') {
                $existing->update(['method' => 'virtual', 'hms_peer_id' => $hmsPeerId]);
            }

            return $existing;
        }

        $attendance = MeetingAttendance::query()->create([
            'id' => Uuid::v4(),
            'meeting_id' => $meeting->id,
            'user_id' => $user->id,
            'checked_in_at' => now(),
            'method' => $method,
            'hms_peer_id' => $hmsPeerId,
        ]);

        $this->audit->record(
            $election?->id,
            ElectionAuditAction::AttendanceCheckedIn,
            'member',
            (string) $user->id,
            ['meeting_id' => $meeting->id, 'method' => $method],
        );

        return $attendance;
    }

    public function confirmQuorum(Meeting $meeting, AdminUser $admin): Meeting
    {
        $snap = $this->snapshot($meeting);
        if (! $snap['quorum_met']) {
            throw ValidationException::withMessages(['quorum' => 'Quorum has not been achieved.']);
        }

        $meeting->update(['quorum_confirmed_at' => now()]);

        $this->audit->record(
            $meeting->primaryElection()?->id,
            ElectionAuditAction::QuorumConfirmed,
            'admin',
            (string) $admin->id,
            array_merge($snap, ['meeting_id' => $meeting->id]),
        );

        return $meeting->fresh();
    }
}
