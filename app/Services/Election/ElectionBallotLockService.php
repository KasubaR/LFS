<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionStatus;
use App\Models\AdminUser;
use App\Models\Election;
use Illuminate\Validation\ValidationException;

class ElectionBallotLockService
{
    public function __construct(
        private readonly ElectionAuditService $audit,
    ) {}

    public function approve(Election $election, AdminUser $admin): Election
    {
        if (! $election->isRollLocked()) {
            throw ValidationException::withMessages(['ballot' => 'Lock the voters’ roll before approving the ballot.']);
        }

        if ($election->positions()->count() < 1) {
            throw ValidationException::withMessages(['ballot' => 'Add at least one position before approving.']);
        }

        foreach ($election->positions as $position) {
            if ($position->candidates()->where('is_active', true)->count() < 1) {
                throw ValidationException::withMessages([
                    'ballot' => "Position \"{$position->title}\" needs at least one candidate.",
                ]);
            }
        }

        if ($election->ballot_approved_at !== null) {
            throw ValidationException::withMessages(['ballot' => 'Ballot is already approved.']);
        }

        $election->update([
            'ballot_approved_at' => now(),
            'status' => $election->scheduled_open_at
                ? ElectionStatus::Scheduled
                : ElectionStatus::BallotApproved,
        ]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::BallotApproved,
            'admin',
            (string) $admin->id,
        );

        return $election->fresh();
    }

    public function unlock(Election $election, AdminUser $admin): Election
    {
        if ($election->isOpen() || $election->isClosedOrLater()) {
            throw ValidationException::withMessages(['ballot' => 'Cannot unlock the ballot after voting has opened.']);
        }

        if ($election->ballot_approved_at === null) {
            throw ValidationException::withMessages(['ballot' => 'Ballot is not approved.']);
        }

        $election->update([
            'ballot_approved_at' => null,
            'early_open_override_at' => null,
            'early_open_override_by' => null,
            'early_open_override_second_by' => null,
            'early_open_override_reason' => null,
            'status' => ElectionStatus::BallotPreview,
        ]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::BallotUnlocked,
            'admin',
            (string) $admin->id,
        );

        return $election->fresh();
    }

    public function canOpen(Election $election): bool
    {
        if (! in_array($election->status, [ElectionStatus::BallotApproved, ElectionStatus::Scheduled], true)) {
            return false;
        }

        if ($election->ballot_approved_at === null || ! $election->isRollLocked()) {
            return false;
        }

        if ($this->hasCompletedEarlyOpenOverride($election)) {
            return true;
        }

        $hours = (int) config('elections.ballot_preview_hours', 48);
        $openAt = $election->scheduled_open_at ?? now();

        return $election->ballot_approved_at->lte($openAt->copy()->subHours($hours));
    }

    public function hasCompletedEarlyOpenOverride(Election $election): bool
    {
        return $election->early_open_override_at !== null
            && $election->early_open_override_by
            && $election->early_open_override_second_by
            && (int) $election->early_open_override_by !== (int) $election->early_open_override_second_by;
    }

    public function recordEarlyOpenOverride(Election $election, AdminUser $admin, string $reason): Election
    {
        if ($election->early_open_override_by === null) {
            $election->update([
                'early_open_override_by' => $admin->id,
                'early_open_override_reason' => $reason,
            ]);
        } elseif ((int) $election->early_open_override_by !== (int) $admin->id
            && $election->early_open_override_second_by === null) {
            $election->update([
                'early_open_override_second_by' => $admin->id,
                'early_open_override_at' => now(),
                'early_open_override_reason' => $reason,
            ]);

            $this->audit->record(
                $election->id,
                ElectionAuditAction::EarlyOpenOverride,
                'admin',
                (string) $admin->id,
                [
                    'first_admin' => $election->early_open_override_by,
                    'second_admin' => $admin->id,
                    'reason' => $reason,
                ],
            );
        } else {
            throw ValidationException::withMessages([
                'override' => 'Early-open override already recorded or requires a different EC member.',
            ]);
        }

        return $election->fresh();
    }

    public function open(Election $election, AdminUser $admin, ElectionEntitlementService $entitlements): Election
    {
        if (! $this->canOpen($election)) {
            throw ValidationException::withMessages([
                'election' => 'Cannot open voting yet. Ballot must be approved at least '
                    .config('elections.ballot_preview_hours', 48)
                    .' hours before the scheduled open (or dual EC override).',
            ]);
        }

        $election->update([
            'status' => ElectionStatus::Open,
            'opened_at' => now(),
        ]);

        $entitlements->issueForOpenElection($election);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::VotingOpened,
            'admin',
            (string) $admin->id,
            ['opened_at' => now()->toIso8601String()],
        );

        return $election->fresh();
    }
}
