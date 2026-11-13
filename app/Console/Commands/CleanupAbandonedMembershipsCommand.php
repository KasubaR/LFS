<?php

namespace App\Console\Commands;

use App\Enums\MembershipHistoryEvent;
use App\Enums\MembershipPaymentStatus;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes applications that were started but never paid for: Draft and
 * PendingPayment memberships untouched for a while, with no money taken and
 * no payment in flight. Anything that has ever received money, or has a
 * gateway reference (a charge that might still resolve), is left alone.
 */
class CleanupAbandonedMembershipsCommand extends Command
{
    protected $signature = 'membership:cleanup-abandoned {--days=30 : Remove applications untouched for this many days} {--dry-run : List what would be removed without deleting}';

    protected $description = 'Delete unpaid Draft/PendingPayment membership applications that were abandoned';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $candidates = Membership::query()
            ->whereIn('status', [MembershipStatus::Draft, MembershipStatus::PendingPayment])
            ->where('updated_at', '<', $cutoff)
            ->with('payments')
            ->get();

        $removed = 0;
        foreach ($candidates as $membership) {
            if (! $this->isAbandoned($membership)) {
                continue;
            }

            $removed++;
            $this->line(($dryRun ? '[dry run] ' : '').'Removing '.$membership->status.' application '.$membership->id
                .' (user '.$membership->user_id.', last touched '.$membership->updated_at->toDateString().')');

            if (! $dryRun) {
                $this->remove($membership);
            }
        }

        $this->info(($dryRun ? 'Would remove ' : 'Removed ').$removed.' abandoned application(s).');

        return self::SUCCESS;
    }

    private function isAbandoned(Membership $membership): bool
    {
        foreach ($membership->payments as $payment) {
            $neverCharged = in_array($payment->status, [MembershipPaymentStatus::Pending, MembershipPaymentStatus::Failed], true)
                && (float) $payment->amount_paid <= 0
                && blank($payment->payment_reference)
                && ! $payment->webhook_received;

            if (! $neverCharged) {
                return false;
            }
        }

        // A numbered row is only ever a renewal (new members are numbered at
        // payment). Anything else with a number is not ours to remove.
        if ($membership->membership_number !== null && ! $this->previousMembershipId($membership)) {
            return false;
        }

        return true;
    }

    private function previousMembershipId(Membership $membership): ?string
    {
        $history = MembershipHistory::query()
            ->where('membership_id', $membership->id)
            ->where('event', MembershipHistoryEvent::Renewed)
            ->first();

        $metadata = $history?->metadata;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        return is_array($metadata) ? ($metadata['previousMembershipId'] ?? null) : null;
    }

    private function remove(Membership $membership): void
    {
        DB::transaction(function () use ($membership) {
            // A renewal took over the previous membership's QR token (see
            // MembershipService::startRenewal); give it back so printed cards stay valid.
            $previousId = $this->previousMembershipId($membership);
            if ($previousId && $membership->public_token !== null) {
                $token = $membership->public_token;
                $membership->forceFill(['public_token' => null])->save();

                Membership::query()
                    ->whereKey($previousId)
                    ->whereNull('public_token')
                    ->update(['public_token' => $token]);
            }

            // Payments and history rows go with it (cascading foreign keys).
            $membership->delete();
        });
    }
}
