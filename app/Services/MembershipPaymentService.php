<?php

namespace App\Services;

use App\Enums\MembershipPaymentStatus;
use App\Models\MembershipPayment;
use Illuminate\Support\Facades\DB;

class MembershipPaymentService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $membershipId, int $planId, array $data = []): int
    {
        $status = $data['status'] ?? MembershipPaymentStatus::Pending;

        $payment = MembershipPayment::query()->create([
            'membership_id' => $membershipId,
            'plan_id' => $planId,
            'promotion_id' => $data['promotionId'] ?? null,
            'amount' => (float) ($data['amount'] ?? 0),
            'amount_paid' => (float) ($data['amountPaid'] ?? 0),
            'discount_amount' => array_key_exists('discountAmount', $data) ? (float) $data['discountAmount'] : null,
            'currency' => $data['currency'] ?? config('membership.currency', 'ZMW'),
            'payment_reference' => $data['paymentReference'] ?? null,
            'receipt_number' => $this->maybeAssignReceiptNumber(null, $status),
            'payment_gateway' => $data['paymentGateway'] ?? 'lenco',
            'status' => $status,
            'metadata' => $data['metadata'] ?? null,
        ]);

        return (int) $payment->id;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function recordAmountPaid(int $id, float $amountPaid, array $extra = []): ?array
    {
        $payment = MembershipPayment::query()->find($id);
        if (! $payment) {
            return null;
        }

        if (MembershipPaymentStatus::isTerminal($payment->status)) {
            return null;
        }

        $status = MembershipPaymentStatus::resolveFromAmounts(
            (float) $payment->amount,
            $amountPaid
        );

        $updates = [
            'amount_paid' => $amountPaid,
            'status' => $status,
            'updated_at' => now(),
        ];

        if ($status === MembershipPaymentStatus::Paid) {
            $updates['paid_at'] = $extra['paidAt'] ?? now();
        }

        $receiptNumber = $this->maybeAssignReceiptNumber($payment->receipt_number, $status);
        if ($receiptNumber !== null) {
            $updates['receipt_number'] = $receiptNumber;
        }

        $map = [
            'paymentReference' => 'payment_reference',
            'coversFrom' => 'covers_from',
            'coversTo' => 'covers_to',
            'lencoTransactionId' => 'lenco_transaction_id',
            'lencoReference' => 'lenco_reference',
            'lencoProvider' => 'lenco_provider',
            'lencoStatus' => 'lenco_status',
            'lencoResponse' => 'lenco_response',
            'webhookReceived' => 'webhook_received',
            'webhookPayload' => 'webhook_payload',
            'webhookReceivedAt' => 'webhook_received_at',
            'pendingChargeAmount' => 'pending_charge_amount',
            'metadata' => 'metadata',
        ];

        foreach ($map as $camel => $column) {
            if (array_key_exists($camel, $extra)) {
                $updates[$column] = $extra[$camel];
            }
        }

        // Atomic conditional update: the terminal-status exclusion is evaluated as
        // part of the same UPDATE statement, so a concurrent webhook/verify/poller
        // call can't both pass a stale check and double-apply the payment.
        $applied = MembershipPayment::query()
            ->whereKey($id)
            ->whereNotIn('status', MembershipPaymentStatus::TERMINAL)
            ->update($updates) > 0;

        if (! $applied) {
            return null;
        }

        return $this->findById($id);
    }

    /**
     * Resolves how much a just-confirmed Lenco charge should add to a
     * payment, and the updates that consume it. A confirmed charge only ever
     * covers what was actually requested for it (pending_charge_amount, set
     * when the MoMo prompt was sent — see recordGatewayInitiation()) — this
     * adds that to whatever was already paid, rather than assuming any
     * successful charge clears the payment in full (a charge can be a
     * partial installment now, see the Jan-Dec grace-period redesign).
     *
     * Returns null when there's nothing pending to credit — i.e.
     * pending_charge_amount is already null, meaning this confirmation is a
     * replay/duplicate (a second verify() poll, a redelivered webhook, or
     * both racing) for an attempt this same method already processed.
     * Callers must skip crediting entirely in that case, not just skip with
     * a fallback, or a replay would double-credit the same charge.
     *
     * The returned `extra` clears payment_reference and pending_charge_amount
     * as part of the same update that credits the charge, so (a) a later
     * top-up isn't blocked by callers' "already in progress" checks against a
     * stale reference, and (b) a replayed confirmation has nothing left to
     * double-credit.
     *
     * @param  array<string, mixed>  $payment
     * @return array{amountPaid: float, extra: array<string, mixed>}|null
     */
    public function resolveConfirmedCharge(array $payment): ?array
    {
        if ($payment['pendingChargeAmount'] === null) {
            return null;
        }

        $amountPaid = min(
            (float) $payment['amount'],
            round((float) $payment['amountPaid'] + (float) $payment['pendingChargeAmount'], 2)
        );

        return [
            'amountPaid' => $amountPaid,
            'extra' => [
                'paymentReference' => null,
                'pendingChargeAmount' => null,
            ],
        ];
    }

    /**
     * Mark a non-terminal payment as failed (gateway declined / cancelled).
     * Membership stays PendingPayment so the member can initiate a fresh attempt.
     *
     * A PartiallyPaid payment already has real money credited against it —
     * a failed/cancelled callback in that state can only be for a top-up
     * *attempt* layered on top (see resolveConfirmedCharge()), never for the
     * original payment itself, so it must not flip the whole row to the
     * terminal Failed status and destroy the successful partial payment.
     * That case is delegated to clearFailedTopUpAttempt() instead, which
     * clears the in-flight attempt and leaves the payment retryable.
     *
     * @param  array<string, mixed>  $extra
     */
    public function markFailed(int $id, array $extra = []): ?array
    {
        $payment = MembershipPayment::query()->find($id);
        if (! $payment || MembershipPaymentStatus::isTerminal($payment->status)) {
            return null;
        }

        if ($payment->status === MembershipPaymentStatus::PartiallyPaid) {
            return $this->clearFailedTopUpAttempt($id, $extra);
        }

        $updates = [
            'status' => MembershipPaymentStatus::Failed,
            'updated_at' => now(),
        ];

        $map = [
            'lencoStatus' => 'lenco_status',
            'lencoResponse' => 'lenco_response',
            'webhookReceived' => 'webhook_received',
            'webhookPayload' => 'webhook_payload',
            'webhookReceivedAt' => 'webhook_received_at',
            'metadata' => 'metadata',
        ];

        foreach ($map as $camel => $column) {
            if (array_key_exists($camel, $extra)) {
                $updates[$column] = $extra[$camel];
            }
        }

        $applied = MembershipPayment::query()
            ->whereKey($id)
            ->whereNotIn('status', MembershipPaymentStatus::TERMINAL)
            ->update($updates) > 0;

        if (! $applied) {
            return null;
        }

        return $this->findById($id);
    }

    /**
     * A failed/cancelled gateway callback for a top-up attempt on a payment
     * that's already PartiallyPaid. Clears the in-flight attempt's gateway
     * reference and pending_charge_amount — so the "already in progress"
     * check in MembershipPaymentController::initiate() no longer blocks a
     * retry, and a later replayed callback has nothing left to double-apply
     * — while recording the failure details for the audit trail. Status
     * stays PartiallyPaid throughout; the money already paid is untouched.
     *
     * @param  array<string, mixed>  $extra
     */
    private function clearFailedTopUpAttempt(int $id, array $extra): ?array
    {
        $updates = [
            'payment_reference' => null,
            'pending_charge_amount' => null,
            'updated_at' => now(),
        ];

        $map = [
            'lencoStatus' => 'lenco_status',
            'lencoResponse' => 'lenco_response',
            'webhookReceived' => 'webhook_received',
            'webhookPayload' => 'webhook_payload',
            'webhookReceivedAt' => 'webhook_received_at',
            'metadata' => 'metadata',
        ];

        foreach ($map as $camel => $column) {
            if (array_key_exists($camel, $extra)) {
                $updates[$column] = $extra[$camel];
            }
        }

        $applied = MembershipPayment::query()
            ->whereKey($id)
            ->where('status', MembershipPaymentStatus::PartiallyPaid)
            ->update($updates) > 0;

        if (! $applied) {
            return null;
        }

        return $this->findById($id);
    }

    /**
     * Persist gateway identifiers returned when a payment is initiated with Lenco,
     * without touching amount_paid/status (the payment is still Pending at this point).
     *
     * @param  array<string, mixed>  $data
     */
    public function recordGatewayInitiation(int $id, array $data): void
    {
        $map = [
            'paymentReference' => 'payment_reference',
            'lencoTransactionId' => 'lenco_transaction_id',
            'lencoReference' => 'lenco_reference',
            'lencoProvider' => 'lenco_provider',
            'lencoStatus' => 'lenco_status',
            'lencoResponse' => 'lenco_response',
            'webhookReceived' => 'webhook_received',
            'webhookPayload' => 'webhook_payload',
            'webhookReceivedAt' => 'webhook_received_at',
            'pendingChargeAmount' => 'pending_charge_amount',
            'metadata' => 'metadata',
        ];

        $updates = ['updated_at' => now()];
        foreach ($map as $camel => $column) {
            if (array_key_exists($camel, $data)) {
                $updates[$column] = $data[$camel];
            }
        }

        MembershipPayment::query()->whereKey($id)->update($updates);
    }

    /**
     * A dedicated, sequential, human-readable receipt id — distinct from
     * payment_reference (the Lenco gateway reference, or, for legacy
     * imports, the membership number). Mirrors
     * MembershipService::generateMembershipNumber()'s counter-row pattern.
     */
    public function generateReceiptNumber(): string
    {
        $prefix = config('membership.receipt_number_prefix', 'LFS-RCT');

        $counter = DB::table('receipt_number_counters')
            ->where('prefix', $prefix)
            ->lockForUpdate()
            ->first();

        if ($counter === null) {
            $latest = MembershipPayment::query()
                ->where('receipt_number', 'like', $prefix.'-%')
                ->orderByDesc('receipt_number')
                ->value('receipt_number');

            $sequence = 1;
            if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
                $sequence = (int) $matches[1] + 1;
            }

            DB::table('receipt_number_counters')->insert([
                'prefix' => $prefix,
                'next_value' => $sequence + 1,
            ]);

            return sprintf('%s-%06d', $prefix, $sequence);
        }

        $sequence = (int) $counter->next_value;

        DB::table('receipt_number_counters')
            ->where('prefix', $prefix)
            ->update(['next_value' => $sequence + 1]);

        return sprintf('%s-%06d', $prefix, $sequence);
    }

    /**
     * Returns a freshly generated receipt number if this payment just became
     * eligible for one (Paid or PartiallyPaid, and doesn't already have one);
     * otherwise null, meaning "leave it as is".
     */
    private function maybeAssignReceiptNumber(?string $existing, string $status): ?string
    {
        if ($existing !== null) {
            return null;
        }

        if (! in_array($status, [MembershipPaymentStatus::Paid, MembershipPaymentStatus::PartiallyPaid], true)) {
            return null;
        }

        return $this->generateReceiptNumber();
    }

    public function findByReference(string $reference): ?array
    {
        $payment = MembershipPayment::query()
            ->with('plan')
            ->where('payment_reference', $reference)
            ->first();

        return $payment ? $this->toPayment($payment) : null;
    }

    public function findByLencoReference(string $reference): ?array
    {
        $payment = MembershipPayment::query()
            ->with('plan')
            ->where('lenco_reference', $reference)
            ->first();

        return $payment ? $this->toPayment($payment) : null;
    }

    public function findByLencoTransactionId(string $transactionId): ?array
    {
        $payment = MembershipPayment::query()
            ->with('plan')
            ->where('lenco_transaction_id', $transactionId)
            ->first();

        return $payment ? $this->toPayment($payment) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCoverage(int $id, array $data): bool
    {
        $updates = [];

        if (array_key_exists('coversFrom', $data)) {
            $updates['covers_from'] = $data['coversFrom'];
        }

        if (array_key_exists('coversTo', $data)) {
            $updates['covers_to'] = $data['coversTo'];
        }

        if ($updates === []) {
            return false;
        }

        $updates['updated_at'] = now();

        return MembershipPayment::query()->whereKey($id)->update($updates) > 0;
    }

    public function findById(int $id): ?array
    {
        $payment = MembershipPayment::query()->with('plan')->find($id);

        return $payment ? $this->toPayment($payment) : null;
    }

    public function findLatestForMembership(string $membershipId): ?array
    {
        $payment = MembershipPayment::query()
            ->with('plan')
            ->where('membership_id', $membershipId)
            ->orderByDesc('created_at')
            ->first();

        return $payment ? $this->toPayment($payment) : null;
    }

    /**
     * All payments across every membership (including past/expired ones from
     * renewals) that belongs to this user, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        return MembershipPayment::query()
            ->with(['plan', 'membership'])
            ->whereHas('membership', fn ($q) => $q->where('user_id', $userId))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MembershipPayment $payment) => $this->toPayment($payment))
            ->all();
    }

    /**
     * Fetch a payment only if it belongs to a membership owned by this user —
     * used to authorize receipt downloads.
     */
    public function findOwnedByUser(int $paymentId, int $userId): ?MembershipPayment
    {
        return MembershipPayment::query()
            ->with(['plan', 'membership.user'])
            ->whereKey($paymentId)
            ->whereHas('membership', fn ($q) => $q->where('user_id', $userId))
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayment(MembershipPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'membershipId' => $payment->membership_id,
            'membershipNumber' => $payment->relationLoaded('membership') ? $payment->membership?->membership_number : null,
            'planId' => $payment->plan_id,
            'planName' => $payment->plan?->name,
            'promotionId' => $payment->promotion_id,
            'amount' => (float) $payment->amount,
            'amountPaid' => (float) $payment->amount_paid,
            'pendingChargeAmount' => $payment->pending_charge_amount !== null ? (float) $payment->pending_charge_amount : null,
            'discountAmount' => $payment->discount_amount !== null ? (float) $payment->discount_amount : null,
            'currency' => $payment->currency,
            'paymentReference' => $payment->payment_reference,
            'receiptNumber' => $payment->receipt_number,
            'paymentGateway' => $payment->payment_gateway,
            'status' => $payment->status,
            'paidAt' => $payment->paid_at ? (string) $payment->paid_at : null,
            'coversFrom' => $payment->covers_from?->toDateString(),
            'coversTo' => $payment->covers_to?->toDateString(),
            'lencoTransactionId' => $payment->lenco_transaction_id,
            'lencoReference' => $payment->lenco_reference,
            'lencoProvider' => $payment->lenco_provider,
            'lencoStatus' => $payment->lenco_status,
            'lencoResponse' => $payment->lenco_response,
            'webhookReceived' => (bool) $payment->webhook_received,
            'webhookPayload' => $payment->webhook_payload,
            'webhookReceivedAt' => $payment->webhook_received_at ? (string) $payment->webhook_received_at : null,
            'metadata' => $payment->metadata,
            'createdAt' => (string) $payment->created_at,
            'updatedAt' => (string) $payment->updated_at,
        ];
    }
}
