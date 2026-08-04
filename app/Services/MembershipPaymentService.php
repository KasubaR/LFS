<?php

namespace App\Services;

use App\Enums\MembershipPaymentStatus;
use App\Models\MembershipPayment;

class MembershipPaymentService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $membershipId, int $planId, array $data = []): int
    {
        $payment = MembershipPayment::query()->create([
            'membership_id' => $membershipId,
            'plan_id' => $planId,
            'amount' => (float) ($data['amount'] ?? 0),
            'amount_paid' => (float) ($data['amountPaid'] ?? 0),
            'currency' => $data['currency'] ?? config('membership.currency', 'ZMW'),
            'payment_reference' => $data['paymentReference'] ?? null,
            'payment_gateway' => $data['paymentGateway'] ?? 'lenco',
            'status' => $data['status'] ?? MembershipPaymentStatus::Pending,
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
     * Mark a non-terminal payment as failed (gateway declined / cancelled).
     * Membership stays PendingPayment so the member can initiate a fresh attempt.
     *
     * @param  array<string, mixed>  $extra
     */
    public function markFailed(int $id, array $extra = []): ?array
    {
        $payment = MembershipPayment::query()->find($id);
        if (! $payment || MembershipPaymentStatus::isTerminal($payment->status)) {
            return null;
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
            'amount' => (float) $payment->amount,
            'amountPaid' => (float) $payment->amount_paid,
            'currency' => $payment->currency,
            'paymentReference' => $payment->payment_reference,
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
