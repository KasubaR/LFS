<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionEntitlementType;
use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Models\ElectionBallotEntitlement;
use App\Models\ElectionCandidate;
use App\Models\ElectionVoteOutbox;
use App\Models\User;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ElectionBallotService
{
    public function __construct(
        private readonly ElectionAuditService $audit,
        private readonly ElectionVoteCrypto $crypto,
    ) {}

    /**
     * Consume entitlement and enqueue encrypted vote (no correlatable immediate vote insert).
     *
     * @param  array{candidate_id?: ?string, abstain?: bool}  $choice
     */
    public function cast(Election $election, User $user, string $entitlementId, array $choice): void
    {
        if ($election->status !== ElectionStatus::Open) {
            throw ValidationException::withMessages(['vote' => 'Voting is not open.']);
        }

        DB::transaction(function () use ($election, $user, $entitlementId, $choice) {
            /** @var ElectionBallotEntitlement|null $entitlement */
            $entitlement = ElectionBallotEntitlement::query()
                ->where('id', $entitlementId)
                ->where('election_id', $election->id)
                ->where('holder_user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($entitlement === null || ! $entitlement->isAvailable()) {
                throw ValidationException::withMessages(['vote' => 'This ballot is not available or has already been used.']);
            }

            $position = $entitlement->position()->firstOrFail();
            $abstain = (bool) ($choice['abstain'] ?? false);
            $candidateId = $choice['candidate_id'] ?? null;

            if ($abstain) {
                if (! $position->allow_abstain) {
                    throw ValidationException::withMessages(['vote' => 'Abstain is not allowed for this position.']);
                }
                $payload = ['abstain' => true, 'candidate_id' => null];
            } else {
                if (! is_string($candidateId) || $candidateId === '') {
                    throw ValidationException::withMessages(['vote' => 'Select a candidate or abstain.']);
                }

                $valid = ElectionCandidate::query()
                    ->where('id', $candidateId)
                    ->where('position_id', $position->id)
                    ->where('is_active', true)
                    ->exists();

                if (! $valid) {
                    throw ValidationException::withMessages(['vote' => 'Invalid candidate.']);
                }

                $payload = ['abstain' => false, 'candidate_id' => $candidateId];
            }

            $blindId = Uuid::v4();
            $ciphertext = $this->crypto->withVoteKey(
                fn () => $this->crypto->encrypt($payload)
            );

            $entitlement->update(['used_at' => now()]);

            ElectionVoteOutbox::query()->create([
                'blind_ballot_id' => $blindId,
                'election_id' => $election->id,
                'position_id' => $position->id,
                'ciphertext' => $ciphertext,
                'status' => 'pending',
                'attempts' => 0,
            ]);

            $action = $entitlement->type === ElectionEntitlementType::Proxy
                ? ElectionAuditAction::ProxyBallotUsed
                : ElectionAuditAction::BallotUsed;

            $this->audit->record(
                $election->id,
                $action,
                'member',
                (string) $user->id,
                [
                    'position_id' => $position->id,
                    'type' => $entitlement->type,
                ],
            );
        });
    }
}
