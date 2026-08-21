<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionEntitlementType;
use App\Enums\ElectionProxyStatus;
use App\Enums\ElectionStatus;
use App\Enums\ElectionVoterMatchStatus;
use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionProxy;
use App\Models\ElectionVoter;
use App\Models\User;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ElectionProxyService
{
    public function __construct(
        private readonly ElectionAuditService $audit,
    ) {}

    public function approve(Election $election, ElectionVoter $grantor, int $holderUserId, AdminUser $admin): ElectionProxy
    {
        if (! $election->isRollLocked()) {
            throw ValidationException::withMessages(['proxy' => 'Lock the voters’ roll before approving proxies.']);
        }

        if ($election->isOpen() || $election->isClosedOrLater()) {
            throw ValidationException::withMessages(['proxy' => 'Proxies must be approved before voting opens.']);
        }

        if ($grantor->election_id !== $election->id
            || $grantor->excluded_at !== null
            || $grantor->match_status !== ElectionVoterMatchStatus::Matched
            || $grantor->user_id === null) {
            throw ValidationException::withMessages(['proxy' => 'Grantor must be a matched, non-excluded voter on the roll.']);
        }

        if ((int) $grantor->user_id === $holderUserId) {
            throw ValidationException::withMessages(['proxy' => 'A member cannot hold their own proxy.']);
        }

        User::query()->findOrFail($holderUserId);

        return DB::transaction(function () use ($election, $grantor, $holderUserId, $admin) {
            // Lock the election row so two concurrent approvals for the same
            // holder can't both pass the max-proxies count check before
            // either has inserted — without this, two requests arriving
            // together near the limit could both succeed and push the
            // holder over the cap.
            Election::query()->where('id', $election->id)->lockForUpdate()->first();

            $max = (int) config('elections.max_proxies_per_holder', 5);
            $held = ElectionProxy::query()
                ->where('election_id', $election->id)
                ->where('holder_user_id', $holderUserId)
                ->where('status', ElectionProxyStatus::Approved)
                ->count();

            if ($held >= $max) {
                throw ValidationException::withMessages([
                    'proxy' => "This member already holds the maximum of {$max} proxies.",
                ]);
            }

            $existing = ElectionProxy::query()
                ->where('election_id', $election->id)
                ->where('grantor_voter_id', $grantor->id)
                ->first();

            if ($existing && $existing->status === ElectionProxyStatus::Approved) {
                throw ValidationException::withMessages(['proxy' => 'This member is already represented by proxy.']);
            }

            $proxy = $existing ?? new ElectionProxy(['id' => Uuid::v4()]);
            $proxy->fill([
                'election_id' => $election->id,
                'grantor_voter_id' => $grantor->id,
                'holder_user_id' => $holderUserId,
                'status' => ElectionProxyStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $admin->id,
            ])->save();

            $grantor->update(['represented_by_proxy' => true]);

            $this->audit->record(
                $election->id,
                ElectionAuditAction::ProxyApproved,
                'admin',
                (string) $admin->id,
                [
                    'proxy_id' => $proxy->id,
                    'grantor_voter_id' => $grantor->id,
                    'holder_user_id' => $holderUserId,
                ],
            );

            return $proxy->fresh();
        });
    }

    public function revoke(Election $election, ElectionProxy $proxy, AdminUser $admin): void
    {
        if ($election->isOpen() || $election->isClosedOrLater()) {
            throw ValidationException::withMessages(['proxy' => 'Cannot revoke proxies after voting has opened.']);
        }

        $proxy->update([
            'status' => ElectionProxyStatus::Revoked,
            'approved_at' => null,
        ]);

        ElectionVoter::query()->where('id', $proxy->grantor_voter_id)->update([
            'represented_by_proxy' => false,
        ]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::ProxyRevoked,
            'admin',
            (string) $admin->id,
            ['proxy_id' => $proxy->id],
        );
    }
}
