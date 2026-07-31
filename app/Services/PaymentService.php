<?php

namespace App\Services;

use App\Models\Payment;

class PaymentService
{
    public const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled', 'refunded'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): int
    {
        $customerInfo = $data['customerInfo'] ?? [];

        $payment = Payment::query()->create([
            'order_number' => $data['orderNumber'],
            'payment_method' => $data['paymentMethod'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'ZMW',
            'status' => $data['status'] ?? 'pending',
            'customer_name' => $customerInfo['name'] ?? '',
            'customer_email' => strtolower($customerInfo['email'] ?? ''),
            'customer_phone' => $customerInfo['phone'] ?? '',
            'lenco_transaction_id' => $data['lencoTransactionId'] ?? null,
            'lenco_reference' => $data['lencoReference'] ?? null,
            'lenco_provider' => $data['lencoProvider'] ?? null,
            'lenco_status' => $data['lencoStatus'] ?? null,
            'lenco_response' => $data['lencoResponse'] ?? null,
            'transaction_id' => $data['transactionId'] ?? null,
            'payment_instructions' => $data['paymentInstructions'] ?? null,
            'expires_at' => $data['expiresAt'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        return (int) $payment->id;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function updateStatus(int $id, string $status, array $extra = []): bool
    {
        $updates = [
            'status' => $status,
            'updated_at' => now(),
        ];

        $map = [
            'lencoStatus' => 'lenco_status',
            'completedAt' => 'completed_at',
            'failureReason' => 'failure_reason',
            'failedAt' => 'failed_at',
            'webhookReceived' => 'webhook_received',
            'webhookPayload' => 'webhook_payload',
            'webhookReceivedAt' => 'webhook_received_at',
        ];

        foreach ($map as $camel => $column) {
            if (array_key_exists($camel, $extra)) {
                $updates[$column] = $extra[$camel];
            }
        }

        // Atomic conditional update: the WHERE clause (including the terminal-status
        // exclusion) is evaluated as part of the same UPDATE statement, so two
        // concurrent callers (e.g. webhook + poller) can't both pass a stale check.
        return Payment::query()
            ->whereKey($id)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->update($updates) > 0;
    }

    public function findById(int $id): ?array
    {
        $payment = Payment::query()->find($id);

        return $payment ? $this->toPayment($payment) : null;
    }

    public function findByTransactionId(string $txId): ?array
    {
        $payment = Payment::query()
            ->where('transaction_id', $txId)
            ->orWhere('lenco_transaction_id', $txId)
            ->first();

        return $payment ? $this->toPayment($payment) : null;
    }

    public function findByLencoReference(string $ref): ?array
    {
        $payment = Payment::query()->where('lenco_reference', $ref)->first();

        return $payment ? $this->toPayment($payment) : null;
    }

    public function findByOrderNumber(string $orderNumber): ?array
    {
        $payment = Payment::query()
            ->where('order_number', $orderNumber)
            ->orderByDesc('created_at')
            ->first();

        return $payment ? $this->toPayment($payment) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'orderNumber' => $payment->order_number,
            'paymentMethod' => $payment->payment_method,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'customerName' => $payment->customer_name,
            'customerEmail' => $payment->customer_email,
            'customerPhone' => $payment->customer_phone,
            'lencoTransactionId' => $payment->lenco_transaction_id,
            'lencoReference' => $payment->lenco_reference,
            'lencoProvider' => $payment->lenco_provider,
            'lencoStatus' => $payment->lenco_status,
            'lencoResponse' => $payment->lenco_response,
            'transactionId' => $payment->transaction_id,
            'paymentInstructions' => $payment->payment_instructions,
            'expiresAt' => $payment->expires_at ? (string) $payment->expires_at : null,
            'completedAt' => $payment->completed_at ? (string) $payment->completed_at : null,
            'failedAt' => $payment->failed_at ? (string) $payment->failed_at : null,
            'failureReason' => $payment->failure_reason,
            'webhookReceived' => (bool) $payment->webhook_received,
            'webhookPayload' => $payment->webhook_payload,
            'webhookReceivedAt' => $payment->webhook_received_at ? (string) $payment->webhook_received_at : null,
            'metadata' => $payment->metadata,
            'createdAt' => (string) $payment->created_at,
            'updatedAt' => (string) $payment->updated_at,
        ];
    }
}
