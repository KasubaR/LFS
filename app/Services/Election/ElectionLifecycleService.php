<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionStatus;
use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionResultCertification;
use App\Support\Uuid;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ElectionLifecycleService
{
    public function __construct(
        private readonly ElectionAuditService $audit,
        private readonly ElectionEntitlementService $entitlements,
        private readonly ElectionVoteFlushService $flush,
        private readonly ElectionTallyService $tally,
        private readonly ElectionBallotLockService $ballotLock,
    ) {}

    public function open(Election $election, AdminUser $admin): Election
    {
        return $this->ballotLock->open($election, $admin, $this->entitlements);
    }

    public function extend(Election $election, AdminUser $admin, \DateTimeInterface $newCloseAt, string $reason): Election
    {
        if ($election->status !== ElectionStatus::Open) {
            throw ValidationException::withMessages(['election' => 'Only an open election can be extended.']);
        }

        $election->update(['scheduled_close_at' => $newCloseAt]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::VotingExtended,
            'admin',
            (string) $admin->id,
            [
                'new_close_at' => $election->scheduled_close_at?->toIso8601String(),
                'reason' => $reason,
            ],
        );

        return $election->fresh();
    }

    public function close(Election $election, AdminUser $admin): Election
    {
        if ($election->status !== ElectionStatus::Open) {
            throw ValidationException::withMessages(['election' => 'Election is not open.']);
        }

        return DB::transaction(function () use ($election, $admin) {
            $election->update([
                'status' => ElectionStatus::Closed,
                'closed_at' => now(),
            ]);

            $this->flush->drainElection($election->id);
            $this->entitlements->expireUnused($election->fresh());

            $tally = $this->tally->tally($election->fresh(['positions.candidates']));
            $election->update(['incomplete_ballot_count' => $tally['incomplete_total']]);

            $this->audit->record(
                $election->id,
                ElectionAuditAction::VotingClosed,
                'admin',
                (string) $admin->id,
                ['closed_at' => now()->toIso8601String()],
            );

            return $election->fresh();
        });
    }

    public function certify(Election $election, AdminUser $admin): Election
    {
        if ($election->status !== ElectionStatus::Closed && $election->status !== ElectionStatus::Certified) {
            throw ValidationException::withMessages(['election' => 'Results can only be certified after close.']);
        }

        $existing = ElectionResultCertification::query()
            ->where('election_id', $election->id)
            ->where('admin_user_id', $admin->id)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages(['election' => 'You have already certified these results.']);
        }

        ElectionResultCertification::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'admin_user_id' => $admin->id,
            'certified_at' => now(),
        ]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::ResultCertified,
            'admin',
            (string) $admin->id,
        );

        $required = (int) config('elections.certifications_required', 2);
        $count = ElectionResultCertification::query()->where('election_id', $election->id)->count();

        if ($count >= $required) {
            $election->update(['status' => ElectionStatus::Certified]);
        }

        return $election->fresh();
    }

    public function permanentLock(Election $election, AdminUser $admin): Election
    {
        if ($election->status !== ElectionStatus::Certified) {
            throw ValidationException::withMessages(['election' => 'Certify results before permanently locking.']);
        }

        $election->update([
            'status' => ElectionStatus::Locked,
            'locked_at' => now(),
        ]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::ElectionLocked,
            'admin',
            (string) $admin->id,
        );

        return $election->fresh();
    }

    public function certificatePdf(Election $election)
    {
        $tally = $this->tally->tally($election->load('positions.candidates'));
        $certs = $election->certifications()->with('admin')->get();

        $pdf = Pdf::loadView('receipts.election-certificate-pdf', [
            'election' => $election,
            'tally' => $tally,
            'certifications' => $certs,
        ])->setPaper('a4');

        $safe = preg_replace('/[^A-Za-z0-9\-_]+/', '-', $election->title) ?: 'election';

        return $pdf->download("election-certificate-{$safe}.pdf");
    }

    public function participationCsv(Election $election): StreamedResponse
    {
        $filename = 'election-participation-'.$election->id.'.csv';

        return response()->streamDownload(function () use ($election) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'membership_number',
                'email',
                'name',
                'attended',
                'represented_by_proxy',
                'entitlements_issued',
                'entitlements_used',
                'entitlements_expired',
            ]);

            $voters = $election->voters()->with('user')->orderBy('raw_name')->get();
            foreach ($voters as $voter) {
                if ($voter->excluded_at) {
                    continue;
                }

                $issued = $voter->user_id
                    ? $election->entitlements()->where('holder_user_id', $voter->user_id)->where('type', 'direct')->count()
                    : 0;
                $used = $voter->user_id
                    ? $election->entitlements()->where('holder_user_id', $voter->user_id)->where('type', 'direct')->whereNotNull('used_at')->count()
                    : 0;
                $expired = $voter->user_id
                    ? $election->entitlements()->where('holder_user_id', $voter->user_id)->where('type', 'direct')->whereNotNull('expired_at')->count()
                    : 0;

                $attended = $voter->user_id
                    ? $election->attendances()->where('user_id', $voter->user_id)->exists()
                    : false;

                fputcsv($out, [
                    $voter->raw_membership_number,
                    $voter->raw_email ?: $voter->user?->email,
                    $voter->raw_name,
                    $attended ? 'yes' : 'no',
                    $voter->represented_by_proxy ? 'yes' : 'no',
                    $issued,
                    $used,
                    $expired,
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
