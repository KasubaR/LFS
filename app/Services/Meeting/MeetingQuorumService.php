<?php

namespace App\Services\Meeting;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionProxyStatus;
use App\Enums\ElectionVoterMatchStatus;
use App\Enums\MeetingStatus;
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
 * Attendance for a Meeting (developer brief §8). There is no quorum
 * requirement; this only records check-ins and summarises turnout. Attendance is
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
     *   represented_by_proxy: int
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
            ];
        }

        return [
            'good_standing' => $this->roll->goodStandingCount($election),
            'attending' => $meeting->attendances()->count(),
            'represented_by_proxy' => ElectionProxy::query()
                ->where('election_id', $election->id)
                ->where('status', ElectionProxyStatus::Approved)
                ->count(),
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
}
