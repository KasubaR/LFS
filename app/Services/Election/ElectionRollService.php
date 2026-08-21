<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionStatus;
use App\Enums\ElectionVoterMatchStatus;
use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionVoter;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ElectionRollService
{
    public function __construct(
        private readonly ElectionAuditService $audit,
    ) {}

    public function lock(Election $election, AdminUser $admin): Election
    {
        if ($election->isRollLocked()) {
            throw ValidationException::withMessages(['roll' => 'Roll is already locked.']);
        }

        $blocking = ElectionVoter::query()
            ->where('election_id', $election->id)
            ->whereNull('excluded_at')
            ->whereIn('match_status', [
                ElectionVoterMatchStatus::Unmatched,
                ElectionVoterMatchStatus::Ambiguous,
            ])
            ->count();

        if ($blocking > 0) {
            throw ValidationException::withMessages([
                'roll' => "Resolve or exclude {$blocking} unmatched/ambiguous voter(s) before locking.",
            ]);
        }

        $eligible = ElectionVoter::query()
            ->where('election_id', $election->id)
            ->whereNull('excluded_at')
            ->where('match_status', ElectionVoterMatchStatus::Matched)
            ->count();

        if ($eligible < 1) {
            throw ValidationException::withMessages(['roll' => 'Cannot lock an empty voters’ roll.']);
        }

        $election->update([
            'roll_locked_at' => now(),
            'status' => ElectionStatus::RollLocked,
        ]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::RollLocked,
            'admin',
            (string) $admin->id,
            ['eligible' => $eligible],
        );

        return $election->fresh();
    }

    public function unlock(Election $election, AdminUser $admin): Election
    {
        if (! $election->isRollLocked()) {
            throw ValidationException::withMessages(['roll' => 'Roll is not locked.']);
        }

        if (in_array($election->status, [ElectionStatus::Open, ElectionStatus::Closed, ElectionStatus::Certified, ElectionStatus::Locked], true)) {
            throw ValidationException::withMessages(['roll' => 'Cannot unlock the roll after voting has opened.']);
        }

        $election->update([
            'roll_locked_at' => null,
            'status' => ElectionStatus::RollImported,
        ]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::RollUnlocked,
            'admin',
            (string) $admin->id,
        );

        return $election->fresh();
    }

    public function exclude(Election $election, ElectionVoter $voter, AdminUser $admin): void
    {
        if ($election->isRollLocked() && $election->isOpen()) {
            throw ValidationException::withMessages(['roll' => 'Cannot exclude voters while voting is open.']);
        }

        $voter->update(['excluded_at' => now()]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::VoterExcluded,
            'admin',
            (string) $admin->id,
            ['voter_id' => $voter->id],
        );
    }

    public function link(Election $election, ElectionVoter $voter, int $userId, AdminUser $admin): void
    {
        if ($election->isRollLocked()) {
            throw ValidationException::withMessages(['roll' => 'Unlock the roll before linking voters.']);
        }

        $user = User::query()->findOrFail($userId);
        $exists = ElectionVoter::query()
            ->where('election_id', $election->id)
            ->where('user_id', $user->id)
            ->where('id', '!=', $voter->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['user_id' => 'That member is already on this roll.']);
        }

        $membership = Membership::query()->where('user_id', $user->id)->latest()->first();

        $voter->update([
            'user_id' => $user->id,
            'membership_id' => $membership?->id,
            'match_status' => ElectionVoterMatchStatus::Matched,
            'excluded_at' => null,
        ]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::VoterLinked,
            'admin',
            (string) $admin->id,
            ['voter_id' => $voter->id, 'user_id' => $user->id],
        );
    }

    public function goodStandingCount(Election $election): int
    {
        return ElectionVoter::query()
            ->where('election_id', $election->id)
            ->whereNull('excluded_at')
            ->where('match_status', ElectionVoterMatchStatus::Matched)
            ->count();
    }
}
