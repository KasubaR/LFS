<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\MembershipHistoryEvent;
use App\Enums\MembershipPaymentStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\CodeException;
use App\Models\Membership;
use App\Models\MembershipHistory;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Support\Uuid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MembershipService
{
    public const INVALID_TRANSITION_CODE = 'INVALID_MEMBERSHIP_TRANSITION';

    public const MEMBERSHIP_NOT_FOUND_CODE = 'MEMBERSHIP_NOT_FOUND';

    public const PLAN_NOT_FOUND_CODE = 'PLAN_NOT_FOUND';

    public const NO_OPEN_MEMBERSHIP_CODE = 'NO_OPEN_MEMBERSHIP';

    public const OPEN_MEMBERSHIP_EXISTS_CODE = 'OPEN_MEMBERSHIP_EXISTS';

    public const PLAN_CHANGE_LOCKED_CODE = 'PLAN_CHANGE_LOCKED';

    public function __construct(
        private readonly MembershipPaymentService $paymentService,
        private readonly MembershipPlanService $planService
    ) {}

    /**
     * @return array{membershipId: string, membershipNumber: ?string}
     */
    public function createApplication(int $userId, int $planId): array
    {
        $plan = $this->findPlanOrFail($planId);

        return DB::transaction(function () use ($userId, $plan) {
            // Serialize concurrent apply attempts for the same user so two
            // Drafts cannot slip past a TOCTOU check.
            User::query()->whereKey($userId)->lockForUpdate()->first();

            if ($this->hasOpenMembership($userId)) {
                throw new CodeException('User already has an open membership application.', self::OPEN_MEMBERSHIP_EXISTS_CODE);
            }

            $membershipId = Uuid::v4();

            // No membership_number yet — it's only allocated once payment is
            // confirmed (see activateOnPayment). Unpaid applications show as
            // "Pending" until then.
            Membership::query()->create([
                'id' => $membershipId,
                'user_id' => $userId,
                'membership_number' => null,
                'status' => MembershipStatus::Draft,
                'current_plan_id' => $plan->id,
                'approval_status' => ApprovalStatus::Pending,
            ]);

            $this->logHistory(
                $membershipId,
                $userId,
                MembershipHistoryEvent::Created,
                null,
                MembershipStatus::Draft,
                $plan->id,
                'member',
                null,
                ['action' => 'application_created']
            );

            return [
                'membershipId' => $membershipId,
                'membershipNumber' => null,
            ];
        });
    }

    public function userHasOpenMembership(int $userId): bool
    {
        return $this->hasOpenMembership($userId);
    }

    /**
     * Switch the plan on a member's unpaid application (Draft or PendingPayment).
     * If a payment row already exists for it (PendingPayment), its amount/plan
     * are updated to match so the member is charged the new plan's price.
     *
     * @return array<string, mixed>
     */
    public function changePlan(int $userId, int $planId): array
    {
        $plan = $this->findPlanOrFail($planId);
        $membership = $this->findOpenMembershipForUser($userId);

        if (! $membership) {
            throw new CodeException('No pending membership application to update.', self::NO_OPEN_MEMBERSHIP_CODE);
        }

        if ((int) $membership->current_plan_id === $plan->id) {
            return $this->toMembership($membership->fresh(['user', 'plan']));
        }

        return DB::transaction(function () use ($membership, $plan) {
            $fromPlanId = (int) $membership->current_plan_id;

            if ($membership->status === MembershipStatus::PendingPayment) {
                $pendingPayment = MembershipPayment::query()
                    ->where('membership_id', $membership->id)
                    ->whereNotIn('status', MembershipPaymentStatus::TERMINAL)
                    ->orderByDesc('created_at')
                    ->lockForUpdate()
                    ->first();

                // Once MoMo has been initiated, the gateway holds the old amount —
                // changing the plan here would activate at the wrong price.
                if ($pendingPayment && filled($pendingPayment->payment_reference)) {
                    throw new CodeException(
                        'Cannot change plan after payment has been initiated. Complete or wait for the current payment to finish.',
                        self::PLAN_CHANGE_LOCKED_CODE
                    );
                }
            }

            Membership::query()->whereKey($membership->id)->update([
                'current_plan_id' => $plan->id,
            ]);

            if ($membership->status === MembershipStatus::PendingPayment) {
                MembershipPayment::query()
                    ->where('membership_id', $membership->id)
                    ->whereNotIn('status', MembershipPaymentStatus::TERMINAL)
                    ->orderByDesc('created_at')
                    ->limit(1)
                    ->update([
                        'plan_id' => $plan->id,
                        'amount' => (float) $plan->price,
                    ]);
            }

            $this->logHistory(
                $membership->id,
                (int) $membership->user_id,
                MembershipHistoryEvent::PlanChanged,
                $membership->status,
                $membership->status,
                $plan->id,
                'member',
                null,
                ['fromPlanId' => $fromPlanId, 'toPlanId' => $plan->id]
            );

            return $this->toMembership($membership->fresh(['user', 'plan']));
        });
    }

    public function userHasActiveMembership(int $userId): bool
    {
        return Membership::query()
            ->where('user_id', $userId)
            ->where('status', MembershipStatus::Active)
            ->where(function ($q) {
                $q->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>=', now()->toDateString());
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{userId: int, membershipId: string, membershipNumber: string, paymentId: int}
     */
    public function importPaidMembership(array $payload): array
    {
        $plan = $this->findPlanOrFail((int) $payload['planId']);
        $registeredAt = Carbon::parse($payload['registeredAt']);
        $dates = $this->computePeriodDates($registeredAt, (int) $plan->duration_months);

        return DB::transaction(function () use ($payload, $plan, $registeredAt, $dates) {
            $membershipId = Uuid::v4();

            Membership::query()->create([
                'id' => $membershipId,
                'user_id' => $payload['userId'],
                'membership_number' => (string) $payload['membershipNumber'],
                'public_token' => Uuid::v4(),
                'status' => MembershipStatus::Active,
                'start_date' => $dates['startDate'],
                'expiry_date' => $dates['expiryDate'],
                'renewal_due_date' => $dates['renewalDueDate'],
                'current_plan_id' => $plan->id,
                'approval_status' => ApprovalStatus::Approved,
                'approved_by' => 'system:import',
                'approved_at' => $registeredAt,
                'joined_at' => $registeredAt,
            ]);

            $paymentId = $this->paymentService->create($membershipId, (int) $plan->id, [
                'amount' => (float) $payload['amountPaid'],
                'amountPaid' => (float) $payload['amountPaid'],
                'status' => MembershipPaymentStatus::Paid,
                'paymentReference' => $payload['paymentReference'] ?? null,
                'paymentGateway' => 'import',
                'metadata' => $payload['metadata'] ?? null,
            ]);

            MembershipPayment::query()->whereKey($paymentId)->update([
                'paid_at' => $registeredAt,
                'covers_from' => $dates['startDate'],
                'covers_to' => $dates['expiryDate'],
            ]);

            $this->logHistory(
                $membershipId,
                (int) $payload['userId'],
                MembershipHistoryEvent::Imported,
                null,
                MembershipStatus::Active,
                (int) $plan->id,
                'admin',
                $payload['importedBy'] ?? 'system:import',
                ['source' => 'excel_import', 'ref' => $payload['membershipNumber']],
            );

            $this->logHistory(
                $membershipId,
                (int) $payload['userId'],
                MembershipHistoryEvent::Activated,
                null,
                MembershipStatus::Active,
                (int) $plan->id,
                'admin',
                'system:import',
                ['paymentId' => $paymentId],
                true
            );

            return [
                'userId' => (int) $payload['userId'],
                'membershipId' => $membershipId,
                'membershipNumber' => (string) $payload['membershipNumber'],
                'paymentId' => $paymentId,
            ];
        });
    }

    public function submitApplication(string $membershipId): array
    {
        $membership = $this->findMembershipOrFail($membershipId);
        $plan = $this->findPlanOrFail((int) $membership->current_plan_id);

        return DB::transaction(function () use ($membership, $membershipId, $plan) {
            $this->transition(
                $membership,
                MembershipStatus::PendingPayment,
                MembershipHistoryEvent::Submitted,
                'member',
                null,
                'Application submitted'
            );

            $paymentId = $this->paymentService->create($membershipId, (int) $plan->id, [
                'amount' => (float) $plan->price,
            ]);

            return $this->toMembership($membership->fresh(['user', 'plan']), $paymentId);
        });
    }

    public function handlePaymentUpdate(int $paymentId, float $amountPaid, array $extra = []): array
    {
        return DB::transaction(function () use ($paymentId, $amountPaid, $extra) {
            $payment = $this->paymentService->recordAmountPaid($paymentId, $amountPaid, $extra);

            // Recovery: a prior attempt may have marked the payment Paid and then
            // crashed before activateOnPayment finished. Terminal payments normally
            // short-circuit, but PendingPayment + Paid must still activate once.
            if (! $payment) {
                $existing = $this->paymentService->findById($paymentId);
                if (
                    $existing
                    && $existing['status'] === MembershipPaymentStatus::Paid
                ) {
                    $membership = $this->findMembershipOrFail($existing['membershipId']);
                    if ($membership->status === MembershipStatus::PendingPayment) {
                        $this->activateOnPayment($membership, $existing);

                        return $this->toMembership($membership->fresh(['user', 'plan']), $paymentId);
                    }
                    if ($membership->status === MembershipStatus::Active) {
                        return $this->toMembership($membership->fresh(['user', 'plan']), $paymentId);
                    }
                }

                throw new CodeException('Payment not found or already terminal.', 'PAYMENT_NOT_UPDATABLE');
            }

            $membership = $this->findMembershipOrFail($payment['membershipId']);

            if (
                $membership->status === MembershipStatus::PendingPayment
                && $payment['status'] === MembershipPaymentStatus::Paid
            ) {
                $this->activateOnPayment($membership, $payment);
                $membership->refresh();
            }

            return $this->toMembership($membership->fresh(['user', 'plan']), $paymentId);
        });
    }

    /**
     * @return array{membershipId: string, membershipNumber: string}
     */
    public function startRenewal(int $userId, int $planId): array
    {
        $plan = $this->findPlanOrFail($planId);
        $latest = $this->findLatestMembershipForUser($userId);

        if (! $latest || $latest->status !== MembershipStatus::Expired) {
            throw new CodeException('No expired membership to renew.', 'NO_EXPIRED_MEMBERSHIP');
        }

        return DB::transaction(function () use ($userId, $plan, $latest) {
            User::query()->whereKey($userId)->lockForUpdate()->first();

            if ($this->hasOpenMembership($userId)) {
                throw new CodeException('User already has an open membership application.', self::OPEN_MEMBERSHIP_EXISTS_CODE);
            }

            $membershipId = Uuid::v4();
            $publicToken = $latest->public_token ?: Uuid::v4();

            // Free the unique token on the superseded row before the renewal
            // row claims it, so printed/saved QR cards stay valid.
            if ($latest->public_token !== null) {
                $latest->forceFill(['public_token' => null])->save();
            }

            Membership::query()->create([
                'id' => $membershipId,
                'user_id' => $userId,
                'membership_number' => $latest->membership_number,
                'public_token' => $publicToken,
                'status' => MembershipStatus::PendingPayment,
                'current_plan_id' => $plan->id,
                'approval_status' => ApprovalStatus::Pending,
                'joined_at' => $latest->joined_at,
            ]);

            $this->logHistory(
                $membershipId,
                $userId,
                MembershipHistoryEvent::Renewed,
                MembershipStatus::Expired,
                MembershipStatus::PendingPayment,
                $plan->id,
                'member',
                null,
                ['previousMembershipId' => $latest->id],
                false
            );

            $paymentId = $this->paymentService->create($membershipId, (int) $plan->id, [
                'amount' => (float) $plan->price,
            ]);

            $membership = $this->findMembershipOrFail($membershipId);

            return array_merge(
                [
                    'membershipId' => $membershipId,
                    'membershipNumber' => $latest->membership_number,
                ],
                $this->toMembership($membership->fresh(['user', 'plan']), $paymentId)
            );
        });
    }

    public function expire(string $membershipId, ?string $notes = null): array
    {
        $membership = $this->findMembershipOrFail($membershipId);

        $this->clearActiveHistoryFlags((int) $membership->user_id);

        $this->transition(
            $membership,
            MembershipStatus::Expired,
            MembershipHistoryEvent::Expired,
            'system',
            null,
            $notes ?? 'Membership period ended'
        );

        return $this->toMembership($membership->fresh(['user', 'plan']));
    }

    public function cancel(string $membershipId, string $adminId, ?string $reason = null): array
    {
        $membership = $this->findMembershipOrFail($membershipId);

        $this->clearActiveHistoryFlags((int) $membership->user_id);

        $this->transition(
            $membership,
            MembershipStatus::Cancelled,
            MembershipHistoryEvent::Cancelled,
            'admin',
            $adminId,
            $reason ?? 'Cancelled by admin'
        );

        return $this->toMembership($membership->fresh(['user', 'plan']));
    }

    /**
     * @return array{startDate: string, expiryDate: string, renewalDueDate: string}
     */
    public function computePeriodDates(Carbon $startDate, int $durationMonths): array
    {
        $expiryDate = $startDate->copy()->addMonths($durationMonths)->subDay();

        return [
            'startDate' => $startDate->toDateString(),
            'expiryDate' => $expiryDate->toDateString(),
            'renewalDueDate' => $expiryDate->toDateString(),
        ];
    }

    public function generateMembershipNumber(): string
    {
        $prefix = config('membership.membership_number_prefix', 'LFS');

        // Counter row + lockForUpdate serializes allocation inside the caller's
        // transaction (renewals reuse numbers and cannot use a UNIQUE column).
        $counter = DB::table('membership_number_counters')
            ->where('prefix', $prefix)
            ->lockForUpdate()
            ->first();

        if ($counter === null) {
            $latest = Membership::query()
                ->where('membership_number', 'like', $prefix.'-%')
                ->orderByDesc('membership_number')
                ->value('membership_number');

            $sequence = 1;
            if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
                $sequence = (int) $matches[1] + 1;
            }

            DB::table('membership_number_counters')->insert([
                'prefix' => $prefix,
                'next_value' => $sequence + 1,
            ]);

            return sprintf('%s-%06d', $prefix, $sequence);
        }

        $sequence = (int) $counter->next_value;

        DB::table('membership_number_counters')
            ->where('prefix', $prefix)
            ->update(['next_value' => $sequence + 1]);

        return sprintf('%s-%06d', $prefix, $sequence);
    }

    public function adminDisplayStatus(string $status): string
    {
        return MembershipStatus::adminDisplayStatus($status);
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return list<array<string, mixed>>
     */
    public function getMembersForAdmin(array $opts = []): array
    {
        $filterStatus = $opts['filterStatus'] ?? '';
        $search = trim((string) ($opts['search'] ?? ''));
        $limit = (int) ($opts['limit'] ?? 100);

        $query = User::query()
            ->with(['satellite', 'memberships' => fn ($q) => $q->orderByDesc('created_at')->limit(1)])
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($filterStatus !== '') {
            $statuses = MembershipStatus::statusesForAdminFilter($filterStatus);
            $query->whereHas('memberships', fn ($q) => $q->whereIn('status', $statuses));
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (! empty($opts['satelliteIds']) && is_array($opts['satelliteIds'])) {
            $query->whereIn('satellite_id', $opts['satelliteIds']);
        }

        return $query->get()
            ->map(fn (User $user) => $this->toMemberListItem($user))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMemberDetailForAdmin(int $userId): ?array
    {
        $user = User::query()
            ->with([
                'satellite',
                'memberships' => fn ($q) => $q->orderByDesc('created_at')->with(['plan', 'user']),
            ])
            ->find($userId);

        if (! $user) {
            return null;
        }

        $history = MembershipHistory::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'nationality' => $user->nationality,
                'tShirtSize' => $user->t_shirt_size,
                'town' => $user->town,
                'satelliteId' => $user->satellite_id,
                'satellite' => $user->satellite?->name,
                'registeredAt' => $user->registered_at ? (string) $user->registered_at : null,
            ],
            'memberships' => $user->memberships
                ->map(fn (Membership $membership) => $this->toMembership($membership))
                ->all(),
            'history' => $history->map(fn (MembershipHistory $entry) => [
                'event' => $entry->event,
                'fromStatus' => $entry->from_status,
                'toStatus' => $entry->to_status,
                'actor' => $entry->actor,
                'notes' => $entry->metadata['notes'] ?? null,
                'createdAt' => (string) $entry->created_at,
            ])->all(),
        ];
    }

    public function findByMembershipId(string $membershipId): ?array
    {
        $membership = Membership::query()->with(['user', 'plan'])->find($membershipId);

        return $membership ? $this->toMembership($membership) : null;
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function activateOnPayment(Membership $membership, array $payment): void
    {
        $plan = $this->findPlanOrFail((int) $membership->current_plan_id);
        $startDate = now();
        $dates = $this->computePeriodDates($startDate, (int) $plan->duration_months);
        $isRenewal = $this->isRenewalMembership($membership);
        $joinedAt = $membership->joined_at ?? $startDate;

        if ($isRenewal) {
            $this->expirePreviousMemberships((int) $membership->user_id, $membership->id);
        }

        $this->clearActiveHistoryFlags((int) $membership->user_id);

        $extra = [
            'start_date' => $dates['startDate'],
            'expiry_date' => $dates['expiryDate'],
            'renewal_due_date' => $dates['renewalDueDate'],
            'approval_status' => ApprovalStatus::Approved,
            'approved_by' => 'system:lenco',
            'approved_at' => now(),
            'joined_at' => $joinedAt,
        ];

        // Renewals already carry the member's existing number forward (set in
        // startRenewal); only a genuinely new membership needs one allocated,
        // and only now that payment has actually been confirmed.
        if ($membership->membership_number === null) {
            $extra['membership_number'] = $this->generateMembershipNumber();
        }

        if ($membership->public_token === null) {
            $extra['public_token'] = Uuid::v4();
        }

        $this->transition(
            $membership,
            MembershipStatus::Active,
            $isRenewal ? MembershipHistoryEvent::Renewed : MembershipHistoryEvent::Activated,
            'lenco',
            'system:lenco',
            $isRenewal ? 'Renewal payment received — membership activated' : 'Payment received — membership activated',
            $extra,
            true
        );

        $this->paymentService->updateCoverage((int) $payment['id'], [
            'coversFrom' => $dates['startDate'],
            'coversTo' => $dates['expiryDate'],
        ]);

        $this->logHistory(
            $membership->id,
            (int) $membership->user_id,
            MembershipHistoryEvent::PaymentReceived,
            MembershipStatus::PendingPayment,
            MembershipStatus::Active,
            (int) $plan->id,
            'lenco',
            'system:lenco',
            [
                'paymentId' => $payment['id'],
                'amount' => $payment['amountPaid'],
            ],
            false
        );
    }

    private function expirePreviousMemberships(int $userId, string $excludeMembershipId): void
    {
        $expired = Membership::query()
            ->where('user_id', $userId)
            ->where('id', '!=', $excludeMembershipId)
            ->where('status', MembershipStatus::Active)
            ->get();

        foreach ($expired as $membership) {
            $this->transition(
                $membership,
                MembershipStatus::Expired,
                MembershipHistoryEvent::Expired,
                'system',
                null,
                'Superseded by renewal'
            );
        }
    }

    private function isRenewalMembership(Membership $membership): bool
    {
        return MembershipHistory::query()
            ->where('membership_id', $membership->id)
            ->where('event', MembershipHistoryEvent::Renewed)
            ->exists();
    }

    private function hasOpenMembership(int $userId): bool
    {
        return Membership::query()
            ->where('user_id', $userId)
            ->whereIn('status', [MembershipStatus::Draft, MembershipStatus::PendingPayment])
            ->exists();
    }

    private function findOpenMembershipForUser(int $userId): ?Membership
    {
        return Membership::query()
            ->where('user_id', $userId)
            ->whereIn('status', [MembershipStatus::Draft, MembershipStatus::PendingPayment])
            ->first();
    }

    private function findLatestMembershipForUser(int $userId): ?Membership
    {
        return Membership::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(
        Membership $membership,
        string $toStatus,
        string $event,
        string $actor,
        ?string $actorId,
        ?string $notes,
        array $extra = [],
        bool $markActive = false
    ): void {
        $fromStatus = $membership->status;

        if (! MembershipStatus::canTransition($fromStatus, $toStatus)) {
            throw new CodeException(
                "Cannot transition membership from {$fromStatus} to {$toStatus}.",
                self::INVALID_TRANSITION_CODE
            );
        }

        $updates = array_merge(['status' => $toStatus], $extra);
        Membership::query()->whereKey($membership->id)->update($updates);
        $membership->refresh();

        $this->logHistory(
            $membership->id,
            (int) $membership->user_id,
            $event,
            $fromStatus,
            $toStatus,
            (int) $membership->current_plan_id,
            $actor,
            $actorId,
            $notes ? ['notes' => $notes] : null,
            $markActive
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function logHistory(
        string $membershipId,
        int $userId,
        string $event,
        ?string $fromStatus,
        ?string $toStatus,
        ?int $planId,
        string $actor,
        ?string $actorId,
        ?array $metadata = null,
        bool $isActive = false
    ): void {
        MembershipHistory::query()->create([
            'membership_id' => $membershipId,
            'user_id' => $userId,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'plan_id' => $planId,
            'metadata' => $metadata,
            'actor' => $actorId ?? $actor,
            'is_active' => $isActive,
            'created_at' => now(),
        ]);
    }

    private function clearActiveHistoryFlags(int $userId): void
    {
        MembershipHistory::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    private function findPlanOrFail(int $planId): MembershipPlan
    {
        $plan = MembershipPlan::query()->find($planId);

        if (! $plan) {
            throw new CodeException('Membership plan not found.', self::PLAN_NOT_FOUND_CODE);
        }

        return $plan;
    }

    private function findMembershipOrFail(string $membershipId): Membership
    {
        $membership = Membership::query()->find($membershipId);

        if (! $membership) {
            throw new CodeException('Membership not found.', self::MEMBERSHIP_NOT_FOUND_CODE);
        }

        return $membership;
    }

    /**
     * @return array<string, mixed>
     */
    private function toMemberListItem(User $user): array
    {
        $membership = $user->memberships->first();
        $nameParts = explode(' ', $user->name, 2);

        return [
            'id' => $user->id,
            'firstName' => $nameParts[0] ?? $user->name,
            'lastName' => $nameParts[1] ?? '',
            'email' => $user->email,
            'phone' => $user->phone ?? '',
            'satellite' => $user->satellite?->name ?? '',
            'membershipNumber' => $membership?->membership_number,
            'status' => $membership
                ? $this->adminDisplayStatus($membership->status)
                : 'inactive',
            'membershipStatus' => $membership?->status,
            'createdAt' => (string) $user->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toMembership(Membership $membership, ?int $latestPaymentId = null): array
    {
        $user = $membership->user;
        $plan = $membership->plan;
        $payment = $latestPaymentId !== null
            ? $this->paymentService->findById($latestPaymentId)
            : $this->paymentService->findLatestForMembership($membership->id);

        return [
            'id' => $membership->id,
            'userId' => (int) $membership->user_id,
            'membershipNumber' => $membership->membership_number,
            'status' => $membership->status,
            'displayStatus' => $this->adminDisplayStatus($membership->status),
            'startDate' => $membership->start_date?->toDateString(),
            'expiryDate' => $membership->expiry_date?->toDateString(),
            'renewalDueDate' => $membership->renewal_due_date?->toDateString(),
            'currentPlanId' => (int) $membership->current_plan_id,
            'approvalStatus' => $membership->approval_status,
            'approvedAt' => $membership->approved_at ? (string) $membership->approved_at : null,
            'approvedBy' => $membership->approved_by,
            'joinedAt' => $membership->joined_at ? (string) $membership->joined_at : null,
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'billingCycle' => $plan->billing_cycle,
                'price' => (float) $plan->price,
                'durationMonths' => (int) $plan->duration_months,
            ] : null,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'satelliteId' => $user->satellite_id,
            ] : null,
            'latestPayment' => $payment,
            'createdAt' => (string) $membership->created_at,
            'updatedAt' => (string) $membership->updated_at,
        ];
    }
}
