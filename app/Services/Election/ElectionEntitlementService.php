<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionEntitlementType;
use App\Enums\ElectionProxyStatus;
use App\Enums\ElectionVoterMatchStatus;
use App\Models\Election;
use App\Models\ElectionBallotEntitlement;
use App\Models\ElectionProxy;
use App\Models\ElectionVoter;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;

class ElectionEntitlementService
{
    public function __construct(
        private readonly ElectionAuditService $audit,
    ) {}

    public function issueForOpenElection(Election $election): int
    {
        $issued = 0;

        DB::transaction(function () use ($election, &$issued) {
            $positions = $election->positions()->get();

            $directVoters = ElectionVoter::query()
                ->where('election_id', $election->id)
                ->whereNull('excluded_at')
                ->where('match_status', ElectionVoterMatchStatus::Matched)
                ->where('represented_by_proxy', false)
                ->whereNotNull('user_id')
                ->get();

            foreach ($directVoters as $voter) {
                foreach ($positions as $position) {
                    if ($this->createEntitlement(
                        $election->id,
                        $position->id,
                        (int) $voter->user_id,
                        ElectionEntitlementType::Direct,
                        null,
                    )) {
                        $issued++;
                        $this->audit->record(
                            $election->id,
                            ElectionAuditAction::BallotIssued,
                            'system',
                            null,
                            ['position_id' => $position->id, 'type' => 'direct'],
                        );
                    }
                }
            }

            $proxies = ElectionProxy::query()
                ->where('election_id', $election->id)
                ->where('status', ElectionProxyStatus::Approved)
                ->get();

            foreach ($proxies as $proxy) {
                foreach ($positions as $position) {
                    if ($this->createEntitlement(
                        $election->id,
                        $position->id,
                        (int) $proxy->holder_user_id,
                        ElectionEntitlementType::Proxy,
                        $proxy->grantor_voter_id,
                    )) {
                        $issued++;
                        $this->audit->record(
                            $election->id,
                            ElectionAuditAction::ProxyBallotIssued,
                            'system',
                            null,
                            ['position_id' => $position->id, 'type' => 'proxy'],
                        );
                    }
                }
            }
        });

        return $issued;
    }

    private function createEntitlement(
        string $electionId,
        string $positionId,
        int $holderUserId,
        string $type,
        ?string $grantorVoterId,
    ): bool {
        $exists = ElectionBallotEntitlement::query()
            ->where('election_id', $electionId)
            ->where('position_id', $positionId)
            ->where('holder_user_id', $holderUserId)
            ->where('type', $type)
            ->when(
                $grantorVoterId === null,
                fn ($q) => $q->whereNull('grantor_voter_id'),
                fn ($q) => $q->where('grantor_voter_id', $grantorVoterId),
            )
            ->exists();

        if ($exists) {
            return false;
        }

        $token = bin2hex(random_bytes(32));

        ElectionBallotEntitlement::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $electionId,
            'position_id' => $positionId,
            'holder_user_id' => $holderUserId,
            'grantor_voter_id' => $grantorVoterId,
            'type' => $type,
            'token_hash' => hash('sha256', $token),
            'issued_at' => now(),
        ]);

        return true;
    }

    /**
     * @return \Illuminate\Support\Collection<int, ElectionBallotEntitlement>
     */
    public function availableForUser(Election $election, int $userId)
    {
        return ElectionBallotEntitlement::query()
            ->with('position.candidates')
            ->where('election_id', $election->id)
            ->where('holder_user_id', $userId)
            ->whereNull('used_at')
            ->whereNull('expired_at')
            ->get();
    }

    public function expireUnused(Election $election): int
    {
        return ElectionBallotEntitlement::query()
            ->where('election_id', $election->id)
            ->whereNull('used_at')
            ->whereNull('expired_at')
            ->update(['expired_at' => $election->closed_at ?? now()]);
    }
}
